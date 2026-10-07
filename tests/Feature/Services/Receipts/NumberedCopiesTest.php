<?php

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\StandingDocument;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Invoicing\InvoicePdfRenderer;
use App\Services\Receipts\NumberedCopies;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts', 'inbox_folder' => '_Inbox']]);
    BusinessProfile::create(['name' => 'Diluno GmbH', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::flush();
    Cache::put('dropbox.access_token', 'access-1', 600);
    Http::preventStrayRequests();
    $this->mock(InvoicePdfRenderer::class)->shouldReceive('pdfBytes')->andReturn('%PDF invoice');

    $july = Statement::create(['source' => 'zkb', 'account_iban' => 'CH93', 'message_id' => 'm', 'statement_ref' => 'jul', 'sequence_number' => 10, 'from_date' => '2026-07-03', 'to_date' => '2026-07-24', 'opening_balance_rappen' => 0, 'closing_balance_rappen' => 100, 'original_filename' => 'j.xml']);
    Statement::create(['source' => 'zkb', 'account_iban' => 'CH93', 'message_id' => 'm', 'statement_ref' => 'aug', 'sequence_number' => 20, 'from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'opening_balance_rappen' => 100, 'closing_balance_rappen' => 100, 'original_filename' => 'a.xml']);
    $line = fn (int $i, string $date, array $attrs) => StatementLine::create($attrs + [
        'statement_id' => $july->id, 'source' => 'zkb', 'bank_ref' => "B{$i}", 'entry_index' => $i, 'booked_on' => $date,
        'is_credit' => false, 'amount_rappen' => 59455, 'currency' => 'CHF',
    ]);
    $this->invoice = Invoice::factory()->paid()->create(['number' => '2026-008', 'total_rappen' => 256520]);
    $this->credit = $line(1, '2026-07-03', ['is_credit' => true, 'amount_rappen' => 256520, 'match_state' => 'matched', 'match_method' => 'number_in_text', 'invoice_id' => $this->invoice->id]);
    $this->other = $line(2, '2026-07-10', ['counterparty_name' => 'Somebody']);
    $this->rent = $line(3, '2026-07-24', ['counterparty_name' => 'Hausverwaltung Muster', 'remittance_text' => 'Miete Diluno Z43', 'bank_tx_code' => 'STDO']);
    $this->document = StandingDocument::create(['label' => 'Office rent', 'row_keyword' => 'miete', 'source_path' => 'mietvertrag-buero.pdf', 'filename' => 'mietvertrag-buero.pdf']);
    $this->copies = app(NumberedCopies::class);
});

function fakeCopies(array $taken = []): void
{
    $respond = fn (string $path) => in_array($path, $taken, true)
        ? Http::response(['error_summary' => 'to/conflict/file/.'], 409)
        : Http::response(['metadata' => ['.tag' => 'file', 'id' => 'id:'.md5($path), 'name' => basename($path), 'path_display' => $path]]);
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/copy_v2' => fn (Request $r) => $respond($r['to_path']),
        'content.dropboxapi.com/2/files/upload' => function (Request $r) use ($taken) {
            $path = json_decode($r->header('Dropbox-API-Arg')[0], true)['path'];

            return in_array($path, $taken, true) ? Http::response(['error_summary' => 'path/conflict/file/.'], 409)
                : Http::response(['id' => 'id:'.md5($path), 'name' => basename($path), 'path_display' => $path]);
        },
    ]);
}

test('the rent contract is copied into the month under the row number and recorded as its receipt', function () {
    fakeCopies();

    $name = $this->copies->create($this->rent);

    expect($name)->toBe('03_mietvertrag-buero.pdf');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'copy_v2')
        && $r['from_path'] === '/Diluno/Receipts/mietvertrag-buero.pdf'
        && $r['to_path'] === '/Diluno/Receipts/2026_Q3/07/03_mietvertrag-buero.pdf' && $r['autorename'] === false);
    $receipt = Receipt::first();
    expect($receipt)->source->toBe('standing')->statement_line_id->toBe($this->rent->id)->match_state->toBe('matched')
        ->filing_status->toBe('filed')->vendor->toBe('Office rent');
    expect($receipt->numbered_at)->not->toBeNull();
    // Nothing to check on a copy: it must not show up as needing attention.
    expect($receipt->isFlagged())->toBeFalse();
    expect(Receipt::needsAttention()->count())->toBe(0);

    // The row now has its document: nothing more to create.
    expect($this->copies->standingFor($this->rent->fresh()))->toBeNull();
    expect(fn () => $this->copies->create($this->rent->fresh()))->toThrow(DomainException::class);
});

test('rows without the keyword, fees and rows marked as needing no receipt get no copy', function () {
    expect($this->copies->standingFor($this->other))->toBeNull();
    $this->rent->update(['no_receipt' => true]);
    expect($this->copies->standingFor($this->rent->fresh()))->toBeNull();
    $this->rent->update(['no_receipt' => false]);
    $this->document->update(['active' => false]);
    expect($this->copies->standingFor($this->rent->fresh()))->toBeNull();
});

test('a paid invoice is filed once as a numbered PDF in the month the money arrived', function () {
    fakeCopies();

    expect($this->copies->create($this->credit))->toBe('01_Diluno-GmbH-Rechnung-2026-008.pdf');

    $this->invoice->refresh();
    expect($this->invoice->dropbox_path)->toBe('/Diluno/Receipts/2026_Q3/07/01_Diluno-GmbH-Rechnung-2026-008.pdf');
    expect($this->invoice->dropbox_file_id)->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'files/upload') && $r->body() === '%PDF invoice'
        && json_decode($r->header('Dropbox-API-Arg')[0], true)['mode'] === 'add');
    expect($this->copies->invoiceToFile($this->credit->fresh()))->toBeFalse();
});

test('an existing file of that name that belongs to another record is never overwritten or taken', function () {
    fakeCopies(taken: ['/Diluno/Receipts/2026_Q3/07/03_mietvertrag-buero.pdf', '/Diluno/Receipts/2026_Q3/07/01_Diluno-GmbH-Rechnung-2026-008.pdf']);
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:owned', 'name' => 'x.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/07/x.pdf'])]);
    Receipt::create(['original_name' => 'x.pdf', 'content_hash' => hash('sha256', 'owned'), 'original_mime' => 'application/pdf', 'size_bytes' => 1, 'dropbox_file_id' => 'id:owned']);

    expect(fn () => $this->copies->create($this->rent))->toThrow(DomainException::class, 'already exists');
    expect(fn () => $this->copies->create($this->credit))->toThrow(DomainException::class, 'already exists');
    expect(Receipt::count())->toBe(1);
    expect($this->invoice->fresh()->dropbox_file_id)->toBeNull();
});

test('a copy that Dropbox already made in a run that was cut off is taken over instead of failing', function () {
    $path = '/Diluno/Receipts/2026_Q3/07/01_Diluno-GmbH-Rechnung-2026-008.pdf';
    fakeCopies(taken: [$path, '/Diluno/Receipts/2026_Q3/07/03_mietvertrag-buero.pdf']);
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => fn (Request $r) => Http::response(['.tag' => 'file', 'id' => 'id:'.md5($r['path']), 'name' => basename($r['path']), 'path_display' => $r['path']])]);

    expect($this->copies->create($this->credit))->toBe('01_Diluno-GmbH-Rechnung-2026-008.pdf');
    expect($this->invoice->fresh()->dropbox_file_id)->toBe('id:'.md5($path));
    expect($this->copies->create($this->rent))->toBe('03_mietvertrag-buero.pdf');
    expect(Receipt::where('source', 'standing')->count())->toBe(1);
});

test('nothing is created for an incomplete month', function () {
    Http::fake();
    Statement::where('statement_ref', 'aug')->delete();

    expect(fn () => $this->copies->create($this->rent))->toThrow(DomainException::class, 'not complete');
    Http::assertNothingSent();
});

test('the month view offers both, counts them, and the month button creates them', function () {
    $this->actingAs(User::factory()->create());
    fakeCopies();

    $this->get('/bank?year=2026')->assertInertia(fn (Assert $page) => $page
        ->where('months.0.key', '2026-07')
        ->where('months.0.lines.0.creates', ['kind' => 'invoice', 'label' => 'invoice PDF'])
        ->where('months.0.lines.1.creates', null)
        ->where('months.0.lines.2.creates', ['kind' => 'standing', 'label' => 'Office rent'])
        ->where('months.0.to_number', 2)
        ->where('months.0.missing', 1));

    $this->post('/bank/months/2026-07/number')->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Numbering 2 file(s)'));

    $this->get('/bank?year=2026')->assertInertia(fn (Assert $page) => $page
        ->where('months.0.to_number', 0)
        ->where('months.0.lines.0.invoice.filed', true)
        ->where('months.0.lines.2.receipts.0.state', 'matched'));
});

test('standing documents are managed in Settings; the file must exist in Dropbox', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => fn (Request $r) => $r['path'] === '/Diluno/Receipts/versicherung.pdf'
        ? Http::response(['.tag' => 'file', 'id' => 'id:v', 'name' => 'versicherung.pdf', 'path_display' => '/Diluno/Receipts/versicherung.pdf'])
        : Http::response(['error_summary' => 'path/not_found/.'], 409)]);

    $this->post('/settings/standing-documents', ['label' => 'X', 'row_keyword' => 'xyz', 'source_path' => 'nope.pdf'])->assertSessionHasErrors('source_path');
    $this->post('/settings/standing-documents', ['label' => 'Insurance', 'row_keyword' => 'Versicherung', 'source_path' => '/versicherung.pdf'])->assertSessionHas('success');

    $added = StandingDocument::where('label', 'Insurance')->first();
    expect($added)->source_path->toBe('versicherung.pdf')->filename->toBe('versicherung.pdf');

    $this->delete("/settings/standing-documents/{$added->id}")->assertSessionHas('success');
    expect(StandingDocument::count())->toBe(1);
});
