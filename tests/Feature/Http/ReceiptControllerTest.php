<?php

use App\Jobs\ExtractReceipt;
use App\Jobs\FileReceipt;
use App\Jobs\PrepareReceiptPdf;
use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    Storage::fake('local');
    Bus::fake();
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

function pdfUpload(string $name = 'Rechnung 4711.pdf', string $body = 'one'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$body}\n%%EOF");
}

function stored(array $attrs = []): Receipt
{
    static $n = 0;
    $n++;

    return Receipt::create($attrs + [
        'original_name' => "r{$n}.pdf", 'content_hash' => hash('sha256', "stored-{$n}"),
        'original_mime' => 'application/pdf', 'size_bytes' => 10,
    ]);
}

test('receipt routes require login', function () {
    auth()->logout();
    $this->get('/receipts')->assertRedirect('/login');
    $this->post('/receipts')->assertRedirect('/login');
});

test('uploading a PDF stores it and queues convert, read, file in that order', function () {
    $this->post('/receipts', ['file' => pdfUpload()])
        ->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('receipt.original_name', 'Rechnung 4711.pdf');

    $receipt = Receipt::first();
    expect($receipt->original_mime)->toBe('application/pdf');
    expect($receipt->extraction_status)->toBe('pending');
    expect($receipt->filing_status)->toBe('pending');
    Storage::disk('local')->assertExists($receipt->local_path);
    Bus::assertChained([PrepareReceiptPdf::class, ExtractReceipt::class, FileReceipt::class]);
});

test('a photo is accepted and keeps its image until converted', function () {
    $this->post('/receipts', ['file' => UploadedFile::fake()->image('IMG_4821.jpg', 600, 800)])->assertCreated();

    expect(Receipt::first()->isPhoto())->toBeTrue();
    expect(Receipt::first()->local_path)->toEndWith('.jpg');
});

test('the same file again is reported as a duplicate and stores nothing', function () {
    $this->post('/receipts', ['file' => pdfUpload()])->assertCreated();
    $first = Receipt::first();

    $this->post('/receipts', ['file' => pdfUpload('other-name.pdf')])
        ->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('receipt.id', $first->id);

    expect(Receipt::count())->toBe(1);
    Bus::assertDispatchedTimes(PrepareReceiptPdf::class, 1);
});

test('other file types and oversize files are rejected', function () {
    $this->postJson('/receipts', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertStatus(422);
    $this->postJson('/receipts', ['file' => UploadedFile::fake()->create('big.pdf', 21000, 'application/pdf')])->assertStatus(422);
    $this->postJson('/receipts', [])->assertStatus(422);

    expect(Receipt::count())->toBe(0);
});

test('the list filters by attention, filing state and month, and counts for the sidebar', function () {
    stored(['extraction_status' => 'done', 'filing_status' => 'filed', 'vendor' => 'Fine', 'document_date' => '2026-09-01', 'confidence' => 'high', 'target_year' => 2026, 'target_month' => 9]);
    stored(['extraction_status' => 'failed', 'filing_status' => 'filed', 'target_year' => 2026, 'target_month' => 9]);
    stored(['extraction_status' => 'done', 'filing_status' => 'failed', 'vendor' => 'Clash', 'document_date' => '2026-10-02', 'confidence' => 'high', 'target_year' => 2026, 'target_month' => 10]);

    // By default the latest quarter is shown; here that is Q4 with one receipt.
    $this->get('/receipts')->assertInertia(fn (Assert $p) => $p->component('Receipts/Index')
        ->where('quarter', ['key' => '2026-Q4', 'label' => 'Q4 2026'])
        ->where('quarters', [['key' => '2026-Q4', 'label' => 'Q4 2026'], ['key' => '2026-Q3', 'label' => 'Q3 2026']])
        ->has('receipts.data', 1)
        ->where('counts.all', 1)
        ->where('months', ['2026-10']));
    $this->get('/receipts?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p
        ->has('receipts.data', 2)->where('counts.attention', 1)->where('months', ['2026-09'])->where('filters.quarter', '2026-Q3'));
    // A receipt still being read has no folder yet and shows in whichever quarter is open.
    $unsorted = stored();
    $this->get('/receipts?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p->has('receipts.data', 3));
    $unsorted->delete();

    $this->get('/receipts?quarter=all')->assertInertia(fn (Assert $p) => $p->component('Receipts/Index')
        ->where('quarter', null)
        ->has('receipts.data', 3)
        ->where('counts', ['all' => 3, 'attention' => 2, 'unfiled' => 1, 'unmatched' => 3, 'numbered' => 0])
        ->where('months', ['2026-10', '2026-09'])
        ->where('dropbox_connected', false)
        ->where('sidebar.nav_counts.receipts', 2));
    $this->get('/receipts?quarter=all&filter=attention')->assertInertia(fn (Assert $p) => $p->has('receipts.data', 2));
    $this->get('/receipts?quarter=all&filter=unfiled')->assertInertia(fn (Assert $p) => $p->has('receipts.data', 1)->where('receipts.data.0.vendor', 'Clash'));
    $this->get('/receipts?quarter=2026-Q3&month=2026-09')->assertInertia(fn (Assert $p) => $p->has('receipts.data', 2)->where('receipts.data.0.folder', '2026_Q3/09'));
});

test('the detail page shows the fields and never the raw text or model output', function () {
    $receipt = stored(['vendor' => 'Netlify', 'total_minor' => 14390, 'currency' => 'USD', 'amounts' => [['amount' => '143.90', 'currency' => 'USD']], 'text_layer' => 'secret text', 'extraction' => ['x' => 1]]);

    $this->get("/receipts/{$receipt->id}")->assertInertia(fn (Assert $p) => $p->component('Receipts/Show')
        ->where('receipt.vendor', 'Netlify')
        ->where('receipt.total', 143.9)
        ->where('receipt.has_text_layer', true)
        ->has('receipt.amounts', 1)
        ->missing('receipt.text_layer')
        ->missing('receipt.extraction'));
});

test('editing fields marks them as corrected; choosing a month marks it as chosen', function () {
    $receipt = stored(['vendor' => 'Netflify', 'target_year' => 2026, 'target_month' => 9]);

    $this->patch("/receipts/{$receipt->id}", [
        'vendor' => 'Netlify', 'document_date' => '2026-09-14', 'total' => '143.90', 'currency' => 'usd',
        'payment_method' => 'card', 'note' => 'yearly plan', 'target_year' => 2026, 'target_month' => 10, 'filename' => 'Netlify Sept.pdf',
    ])->assertRedirect()->assertSessionHas('success');

    $receipt->refresh();
    expect($receipt->vendor)->toBe('Netlify');
    expect($receipt->total_minor)->toBe(14390);
    expect($receipt->currency)->toBe('USD');
    expect($receipt->fields_edited)->toBeTrue();
    expect($receipt->target_edited)->toBeTrue();
    expect($receipt->target_month)->toBe(10);
    expect($receipt->filename)->toBe('Netlify Sept.pdf');
    expect($receipt->note)->toBe('yearly plan');
});

test('saving only a note does not mark fields as corrected', function () {
    $receipt = stored(['vendor' => 'Netlify', 'target_year' => 2026, 'target_month' => 9]);

    $this->patch("/receipts/{$receipt->id}", ['vendor' => 'Netlify', 'note' => 'x', 'target_year' => 2026, 'target_month' => 9]);

    expect($receipt->fresh()->fields_edited)->toBeFalse();
    expect($receipt->fresh()->target_edited)->toBeFalse();
});

test('read again and file now queue their jobs; a filed receipt is not filed twice', function () {
    $receipt = stored(['extraction_status' => 'failed', 'filing_status' => 'failed', 'filing_error' => 'x']);

    $this->post("/receipts/{$receipt->id}/extract")->assertRedirect();
    $this->post("/receipts/{$receipt->id}/file")->assertRedirect();
    Bus::assertDispatched(ExtractReceipt::class);
    Bus::assertDispatched(FileReceipt::class);
    expect($receipt->fresh()->filing_error)->toBeNull();

    $filed = stored(['filing_status' => 'filed', 'dropbox_file_id' => 'id:a']);
    $this->post("/receipts/{$filed->id}/file")->assertSessionHas('error');
    Bus::assertDispatchedTimes(FileReceipt::class, 1);
});

test('removing a receipt deletes the record and local copy but sends nothing to Dropbox', function () {
    Http::fake();
    Storage::disk('local')->put('receipts/x.pdf', 'x');
    $receipt = stored(['local_path' => 'receipts/x.pdf']);
    $filed = stored(['filing_status' => 'filed', 'dropbox_file_id' => 'id:a']);

    $this->delete("/receipts/{$receipt->id}")->assertRedirect('/receipts');
    $this->delete("/receipts/{$filed->id}")->assertRedirect('/receipts')->assertSessionHas('success', 'Receipt removed from ernte. The file stays in Dropbox.');

    expect(Receipt::count())->toBe(0);
    Storage::disk('local')->assertMissing('receipts/x.pdf');
    Http::assertNothingSent();
});

test('the file route streams the local copy', function () {
    Storage::disk('local')->put('receipts/x.pdf', '%PDF-1.4');
    $receipt = stored(['local_path' => 'receipts/x.pdf']);

    $this->get("/receipts/{$receipt->id}/file")->assertOk();
    $this->get('/receipts/'.stored()->id.'/file')->assertNotFound();
});

test('a filed receipt is shown inline from Dropbox, not handed over as a download', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => 'refresh-1']);
    Illuminate\Support\Facades\Cache::put('dropbox.access_token', 'access-1', 600);
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:a', 'name' => 'a.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/a.pdf']),
        'content.dropboxapi.com/2/files/download' => Http::response('%PDF-1.4 from dropbox'),
    ]);
    $filed = stored(['filing_status' => 'filed', 'dropbox_file_id' => 'id:a']);

    $response = $this->get("/receipts/{$filed->id}/file")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');
    expect($response->getContent())->toBe('%PDF-1.4 from dropbox');
});

test('the list marks receipts paid by credit card: by the matched row, else by what the document says', function () {
    $statement = App\Models\Statement::create(['source' => 'viseca', 'account_iban' => 'VISECA', 'message_id' => 'pool', 'statement_ref' => 'pool', 'from_date' => '2026-07-01', 'to_date' => '2026-07-31', 'original_filename' => 'v.csv']);
    $cardRow = App\Models\StatementLine::create(['statement_id' => $statement->id, 'source' => 'viseca', 'bank_ref' => 'T1', 'entry_index' => 1, 'booked_on' => '2026-07-05', 'is_credit' => false, 'amount_rappen' => 100, 'currency' => 'CHF']);
    $bankRow = App\Models\StatementLine::create(['statement_id' => $statement->id, 'source' => 'zkb', 'bank_ref' => 'B1', 'entry_index' => 1, 'booked_on' => '2026-07-05', 'is_credit' => false, 'amount_rappen' => 100, 'currency' => 'CHF']);
    stored(['vendor' => 'A matched card', 'payment_method' => 'unknown', 'statement_line_id' => $cardRow->id, 'match_state' => 'matched', 'target_year' => 2026, 'target_month' => 7]);
    stored(['vendor' => 'B says card, paid by bank', 'payment_method' => 'card', 'statement_line_id' => $bankRow->id, 'match_state' => 'matched', 'target_year' => 2026, 'target_month' => 7]);
    stored(['vendor' => 'C says card', 'payment_method' => 'card', 'target_year' => 2026, 'target_month' => 7]);
    stored(['vendor' => 'D bank', 'payment_method' => 'bank', 'target_year' => 2026, 'target_month' => 7]);

    $this->get('/receipts')->assertInertia(fn (Assert $p) => $p
        ->where('receipts.data', fn ($rows) => collect($rows)->sortBy('vendor')->pluck('paid_by_card')->values()->all() === [true, false, true, false]));
});
