<?php

use App\Jobs\ExtractReceipt;
use App\Jobs\FileReceipt;
use App\Jobs\PrepareReceiptPdf;
use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Services\Dropbox\DropboxClient;
use App\Services\Receipts\PdfText;
use App\Services\Receipts\ReceiptExtractor;
use App\Services\Receipts\ReceiptFiler;
use App\Services\Receipts\ReceiptImageConverter;
use App\Services\Receipts\ReceiptPaths;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::put('dropbox.access_token', 'access-1', 600);
    Storage::fake('local');
    Http::preventStrayRequests();
});

function receipt(array $attrs = [], string $contents = '%PDF-1.4 test'): Receipt
{
    static $n = 0;
    $n++;
    $photo = str_starts_with($attrs['original_mime'] ?? 'application/pdf', 'image/');
    $hash = hash('sha256', "receipt-{$n}");
    $path = "receipts/{$hash}.".($attrs['ext'] ?? 'pdf');
    unset($attrs['ext']);
    Storage::disk('local')->put($path, $contents);

    // created_at is not fillable; set it explicitly so tests can pin the arrival date.
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);
    $receipt = new Receipt($attrs + [
        'original_name' => $photo ? 'IMG_4821.jpg' : 'Rechnung 4711.pdf', 'content_hash' => $hash,
        'original_mime' => 'application/pdf', 'size_bytes' => strlen($contents), 'local_path' => $path,
    ]);
    if ($createdAt) {
        // Eloquent stores the wall-clock of whatever zone the instance carries; normalise to the app zone first.
        $receipt->created_at = Carbon::parse($createdAt)->setTimezone(config('app.timezone'));
    }
    $receipt->save();

    return $receipt;
}

function fields(array $override = []): array
{
    return $override + [
        'vendor' => 'Netlify', 'document_date' => '2026-09-14', 'total' => '143.90', 'currency' => 'USD',
        'amounts' => [['amount' => '143.90', 'currency' => 'USD'], ['amount' => '10.00', 'currency' => 'USD']],
        'invoice_number' => 'INV-1', 'payment_method' => 'card', 'confidence' => 'high',
    ];
}

function fakeExtractor(?array $fields = null, ?Throwable $throws = null): void
{
    $mock = test()->mock(ReceiptExtractor::class);
    $throws ? $mock->shouldReceive('read')->andThrow($throws) : $mock->shouldReceive('read')->andReturn($fields ?? fields());
}

function fakeDropboxUpload(array $uploadResponses): void
{
    $sequence = Http::sequence();
    foreach ($uploadResponses as $r) {
        is_array($r) ? $sequence->push($r) : $sequence->push(['error_summary' => 'path/conflict/file/.'], 409);
    }
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'content.dropboxapi.com/2/files/upload' => $sequence,
    ]);
}

function uploadedPaths(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), '/files/upload'))
        ->map(fn ($pair) => json_decode($pair[0]->header('Dropbox-API-Arg')[0], true)['path'])->values()->all();
}

// ── Naming and folders ──

test('folders follow the quarter/month convention', function () {
    expect(ReceiptPaths::monthFolder('/Diluno/Receipts', 2026, 9))->toBe('/Diluno/Receipts/2026_Q3/09');
    expect(ReceiptPaths::monthFolder('/Diluno/Receipts', 2026, 10))->toBe('/Diluno/Receipts/2026_Q4/10');
    expect(ReceiptPaths::monthFolder('/Diluno/Receipts', 2027, 1))->toBe('/Diluno/Receipts/2027_Q1/01');
    expect(ReceiptPaths::label(2026, 3))->toBe('2026_Q1/03');
});

test('names are made safe for Dropbox and always end in .pdf', function (string $in, string $out) {
    expect(ReceiptPaths::sanitise($in))->toBe($out);
})->with([
    ['Rechnung 4711.pdf', 'Rechnung 4711.pdf'],
    ['Rechnung 4711.PDF', 'Rechnung 4711.pdf'],
    ['a/b\\c:d?.pdf', 'a-b-c-d.pdf'],
    ['  Müller & Söhne  .pdf', 'Müller & Söhne.pdf'],
    ['IMG_1.HEIC', 'IMG_1.pdf'],
    ['...', 'Beleg.pdf'],
]);

test('a photo is named from date and vendor, a PDF keeps its name', function () {
    $photo = receipt(['original_mime' => 'image/jpeg', 'vendor' => 'Coop Genossenschaft Zürich', 'document_date' => '2026-09-14']);
    $unread = receipt(['original_mime' => 'image/jpeg', 'created_at' => '2026-10-07 09:00:00']);
    $pdf = receipt(['vendor' => 'Netlify', 'document_date' => '2026-09-14']);

    expect(ReceiptPaths::initialName($photo))->toBe('2026-09-14_Coop-Genossenschaft-Zurich.pdf');
    expect(ReceiptPaths::initialName($unread))->toBe('Beleg_2026-10-07_'.substr($unread->content_hash, 0, 6).'.pdf');
    expect(ReceiptPaths::initialName($pdf))->toBe('Rechnung 4711.pdf');
});

// ── Extraction ──

test('model output is cleaned into predictable fields', function () {
    $out = ReceiptExtractor::normalise([
        'vendor' => '  Netlify ', 'document_date' => '14.09.2026', 'total' => '143.9', 'currency' => 'usd',
        'amounts' => [['amount' => '143.90', 'currency' => 'USD'], ['amount' => "1'297.20", 'currency' => 'CHF'], ['amount' => '7', 'currency' => null]],
        'invoice_number' => '', 'payment_method' => 'paypal', 'confidence' => 'certain',
    ]);

    expect($out)->toMatchArray([
        'vendor' => 'Netlify', 'document_date' => null, 'total' => '143.90', 'currency' => 'USD',
        'invoice_number' => null, 'payment_method' => 'unknown', 'confidence' => 'low',
    ]);
    expect($out['amounts'])->toBe([['amount' => '143.90', 'currency' => 'USD'], ['amount' => '7.00', 'currency' => null]]);
});

test('extraction stores fields and parks the receipt in the month of its date', function () {
    fakeExtractor();
    $receipt = receipt(['created_at' => Carbon::parse('2026-09-20 10:00:00', 'UTC')]);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    $receipt->refresh();
    expect($receipt->extraction_status)->toBe('done');
    expect($receipt->vendor)->toBe('Netlify');
    expect($receipt->total_minor)->toBe(14390);
    expect($receipt->currency)->toBe('USD');
    expect($receipt->amounts)->toHaveCount(2);
    expect($receipt->payment_method)->toBe('card');
    expect([$receipt->target_year, $receipt->target_month])->toBe([2026, 9]);
    expect($receipt->isFlagged())->toBeFalse();
});

test('an upload dated for an earlier month is parked in the month it arrived', function () {
    fakeExtractor(fields(['document_date' => '2026-09-30']));
    $receipt = receipt(['created_at' => Carbon::parse('2026-10-08 09:17:00', 'UTC')]);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    $receipt->refresh();
    expect($receipt->document_date->toDateString())->toBe('2026-09-30');
    expect([$receipt->target_year, $receipt->target_month])->toBe([2026, 10]);
});

test('a receipt imported from the Dropbox folders keeps its own month however late ernte sees it', function () {
    fakeExtractor(fields(['document_date' => '2026-07-14']));
    $receipt = receipt(['source' => 'existing', 'created_at' => Carbon::parse('2026-10-08 09:17:00', 'UTC')]);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    expect([$receipt->fresh()->target_year, $receipt->fresh()->target_month])->toBe([2026, 7]);
});

test('without a date the upload month in Zurich is used and the receipt is flagged', function () {
    fakeExtractor(fields(['document_date' => null, 'total' => null]));
    // 23:30 UTC on 30 September is already 1 October in Zurich.
    $receipt = receipt(['created_at' => Carbon::parse('2026-09-30 23:30:00', 'UTC')]);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    $receipt->refresh();
    expect([$receipt->target_year, $receipt->target_month])->toBe([2026, 10]);
    expect($receipt->total_minor)->toBeNull();
    expect($receipt->isFlagged())->toBeTrue();
});

test('corrected fields and a hand-set month survive a re-read', function () {
    fakeExtractor();
    $receipt = receipt(['vendor' => 'Netlify Inc.', 'fields_edited' => true, 'target_year' => 2026, 'target_month' => 8, 'target_edited' => true]);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    $receipt->refresh();
    expect($receipt->vendor)->toBe('Netlify Inc.');
    expect($receipt->target_month)->toBe(8);
    expect($receipt->extraction['vendor'])->toBe('Netlify');
});

test('a receipt that cannot be read is marked, parked by upload date, and the job does not fail', function () {
    fakeExtractor(throws: new RuntimeException('model unavailable'));
    $receipt = receipt(['created_at' => '2026-10-05 10:00:00']);
    $job = new ExtractReceipt($receipt->id);
    $job->tries = 1;

    $job->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    $receipt->refresh();
    expect($receipt->extraction_status)->toBe('failed');
    expect($receipt->extraction_error)->toBe('model unavailable');
    expect($receipt->target_month)->toBe(10);
    expect($receipt->isFlagged())->toBeTrue();
});

test('the text layer of a text PDF is stored; a missing pdftotext is not an error', function () {
    $pdf = Browsershot::html('<p>Rechnung 4711 Total CHF 1297.20</p>')->noSandbox()
        ->setChromePath(config('services.browsershot.chrome_path') ?: '/usr/bin/chromium')->pdf();
    fakeExtractor();
    $receipt = receipt(contents: $pdf);

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));
    expect($receipt->fresh()->text_layer)->toContain('1297.20');

    config(['services.receipts.pdftotext_path' => '/nonexistent/pdftotext']);
    $other = receipt(contents: $pdf);
    (new ExtractReceipt($other->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));
    expect($other->fresh()->text_layer)->toBeNull();
    expect($other->fresh()->extraction_status)->toBe('done');
});

// ── Photo conversion ──

test('a photo is scaled down, becomes a one-page PDF, and the image is removed', function () {
    $image = new Imagick;
    $image->newImage(6000, 3000, 'white');
    $image->setImageFormat('jpeg');
    $receipt = receipt(['original_mime' => 'image/jpeg', 'ext' => 'jpg'], $image->getImageBlob());
    $imagePath = $receipt->local_path;

    [, $w, $h] = app(ReceiptImageConverter::class)->normalise($image->getImageBlob());
    expect([$w, $h])->toBe([2400, 1200]); // scaled down to the longest side allowed

    (new PrepareReceiptPdf($receipt->id))->handle(app(ReceiptImageConverter::class));

    $receipt->refresh();
    expect($receipt->local_path)->toEndWith('.pdf');
    expect(Storage::disk('local')->get($receipt->local_path))->toStartWith('%PDF');
    Storage::disk('local')->assertMissing($imagePath);
});

// ── Filing ──

test('a PDF is filed under its original name in the month folder, then the local copy goes', function () {
    fakeDropboxUpload([['id' => 'id:abc', 'name' => 'Rechnung 4711.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/Rechnung 4711.pdf']]);
    $receipt = receipt(['target_year' => 2026, 'target_month' => 9]);
    $local = $receipt->local_path;

    expect(app(ReceiptFiler::class)->file($receipt))->toBeTrue();

    $receipt->refresh();
    expect($receipt->filing_status)->toBe('filed');
    expect($receipt->dropbox_file_id)->toBe('id:abc');
    expect($receipt->filename)->toBe('Rechnung 4711.pdf');
    expect($receipt->local_path)->toBeNull();
    Storage::disk('local')->assertMissing($local);
    expect(uploadedPaths())->toBe(['/Diluno/Receipts/2026_Q3/09/Rechnung 4711.pdf']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_folder_v2') && $r['path'] === '/Diluno/Receipts/2026_Q3');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_folder_v2') && $r['path'] === '/Diluno/Receipts/2026_Q3/09');
});

test('an existing PDF name is never overwritten: the receipt fails and keeps its local copy', function () {
    fakeDropboxUpload(['conflict']);
    $receipt = receipt(['target_year' => 2026, 'target_month' => 9]);

    expect(app(ReceiptFiler::class)->file($receipt))->toBeFalse();

    $receipt->refresh();
    expect($receipt->filing_status)->toBe('failed');
    expect($receipt->filing_error)->toContain('already exists');
    expect($receipt->dropbox_file_id)->toBeNull();
    Storage::disk('local')->assertExists($receipt->local_path);
    expect(uploadedPaths())->toHaveCount(1);
});

test('a generated photo name gets a suffix on conflict', function () {
    fakeDropboxUpload(['conflict', 'conflict', ['id' => 'id:p', 'name' => '2026-09-14_Coop_3.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop_3.pdf']]);
    $receipt = receipt(['original_mime' => 'image/jpeg', 'vendor' => 'Coop', 'document_date' => '2026-09-14', 'target_year' => 2026, 'target_month' => 9]);

    expect(app(ReceiptFiler::class)->file($receipt))->toBeTrue();

    expect(uploadedPaths())->toBe([
        '/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop.pdf',
        '/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop_2.pdf',
        '/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop_3.pdf',
    ]);
    expect($receipt->fresh()->filename)->toBe('2026-09-14_Coop_3.pdf');
});

test('a photo name chosen by hand is not varied', function () {
    fakeDropboxUpload(['conflict']);
    $receipt = receipt(['original_mime' => 'image/jpeg', 'filename' => 'Coop Mittagessen.pdf', 'target_year' => 2026, 'target_month' => 9]);

    expect(app(ReceiptFiler::class)->file($receipt))->toBeFalse();
    expect(uploadedPaths())->toBe(['/Diluno/Receipts/2026_Q3/09/Coop Mittagessen.pdf']);
});

test('without a Dropbox connection the receipt keeps waiting', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => null]);
    Http::fake();
    $receipt = receipt();

    expect(app(ReceiptFiler::class)->file($receipt))->toBeFalse();
    expect($receipt->fresh()->filing_status)->toBe('pending');
    Storage::disk('local')->assertExists($receipt->local_path);
    Http::assertNothingSent();
});

test('a Dropbox outage fails the receipt only after the last retry', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    $receipt = receipt(['target_year' => 2026, 'target_month' => 9]);

    $job = new FileReceipt($receipt->id);
    $job->tries = 1;
    $job->handle(app(ReceiptFiler::class));

    expect($receipt->fresh()->filing_status)->toBe('failed');
    expect($receipt->fresh()->filing_error)->toContain('503');
    Storage::disk('local')->assertExists($receipt->local_path);
});

// ── After filing ──

function filedReceipt(array $attrs = []): Receipt
{
    return receipt($attrs + [
        'filing_status' => 'filed', 'dropbox_file_id' => 'id:abc', 'filename' => 'Rechnung 4711.pdf',
        'dropbox_path' => '/Diluno/Receipts/2026_Q3/08/Rechnung 4711.pdf', 'target_year' => 2026, 'target_month' => 8,
    ]);
}

test('moving to another month looks the file up by id first, keeping a name changed elsewhere', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:abc', 'name' => 'Renamed by accountant.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/08/Renamed by accountant.pdf']),
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/move_v2' => Http::response(['metadata' => ['.tag' => 'file', 'id' => 'id:abc', 'name' => 'Renamed by accountant.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/Renamed by accountant.pdf']]),
    ]);
    $receipt = filedReceipt();

    app(ReceiptFiler::class)->relocate($receipt, 2026, 9);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'move_v2') && $r['to_path'] === '/Diluno/Receipts/2026_Q3/09/Renamed by accountant.pdf');
    expect($receipt->fresh()->target_month)->toBe(9);
    expect($receipt->fresh()->filename)->toBe('Renamed by accountant.pdf');
});

test('a file the scripts already numbered is not moved or renamed', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:abc', 'name' => '07_Rechnung 4711.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/08/07_Rechnung 4711.pdf']),
    ]);
    $receipt = filedReceipt();

    expect(fn () => app(ReceiptFiler::class)->relocate($receipt, 2026, 9))->toThrow(DomainException::class);
    expect($receipt->fresh()->numberPrefix())->toBe('07');
    expect($receipt->fresh()->target_month)->toBe(8);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'move_v2'));
});

test('a file deleted in Dropbox is reported as missing, not re-created', function () {
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => Http::response(['error_summary' => 'path/not_found/.'], 409)]);
    $receipt = filedReceipt();

    app(ReceiptFiler::class)->refresh($receipt);

    expect($receipt->fresh()->filing_status)->toBe('missing');
    expect($receipt->fresh()->isFlagged())->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'upload'));
});
