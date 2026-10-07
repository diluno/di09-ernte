<?php

use App\Jobs\ExtractReceipt;
use App\Jobs\FileReceipt;
use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Services\Receipts\DropboxIntake;
use App\Services\Receipts\ReceiptFiler;
use App\Services\Receipts\ReceiptPaths;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts', 'inbox_folder' => '_Inbox']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::put('dropbox.access_token', 'access-1', 600);
    Storage::fake('local');
    Bus::fake();
    Http::preventStrayRequests();
});

function dbxFile(string $id, string $name, string $folder = '/Diluno/Receipts/_Inbox'): array
{
    return ['.tag' => 'file', 'id' => $id, 'name' => $name, 'path_display' => "{$folder}/{$name}"];
}

/** Fake Dropbox: one listing, metadata/download answered per file id. */
function fakeDropboxFolder(array $entries, array $contents = [], array $extra = []): void
{
    $byId = collect($entries)->keyBy('id');
    Http::fake($extra + [
        'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => $entries, 'has_more' => false]),
        'api.dropboxapi.com/2/files/get_metadata' => fn (Request $r) => Http::response($byId[$r['path']] ?? ['error_summary' => 'path/not_found/.'], isset($byId[$r['path']]) ? 200 : 409),
        'content.dropboxapi.com/2/files/download' => fn (Request $r) => Http::response($contents[json_decode($r->header('Dropbox-API-Arg')[0], true)['path']] ?? '%PDF'),
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
    ]);
}

test('scan names are recognised, real names are not', function (string $name, bool $scan) {
    expect(ReceiptPaths::isScanName($name))->toBe($scan);
})->with([
    ['Scan 7 Oct 2026 at 10.15.pdf', true],
    ['Scan 7. Okt. 2026 um 10.15.pdf', true],
    ['scan.pdf', true],
    ['IMG_4821.pdf', true],
    ['Dokument 3.pdf', true],
    ['Scandinavian Airlines 4711.pdf', false],
    ['Rechnung 4711.pdf', false],
    ['Imagine Invoice.pdf', false],
]);

test('new PDFs in the inbox are registered and queued; other files are skipped; known ones are left', function () {
    Receipt::create(['source' => 'inbox', 'original_name' => 'known.pdf', 'content_hash' => hash('sha256', 'known'), 'original_mime' => 'application/pdf', 'size_bytes' => 1, 'dropbox_file_id' => 'id:known', 'filing_status' => 'inbox']);
    fakeDropboxFolder([
        dbxFile('id:new', 'Scan 7 Oct 2026.pdf'),
        dbxFile('id:known', 'known.pdf'),
        dbxFile('id:photo', 'IMG_1.heic'),
        ['.tag' => 'folder', 'id' => 'id:f', 'name' => 'old', 'path_display' => '/Diluno/Receipts/_Inbox/old'],
    ], ['id:new' => '%PDF scan']);

    $result = app(DropboxIntake::class)->scanInbox();

    expect($result)->toBe(['new' => 1, 'duplicates' => 0, 'skipped' => 1]);
    $receipt = Receipt::where('dropbox_file_id', 'id:new')->first();
    expect($receipt->source)->toBe('inbox');
    expect($receipt->filing_status)->toBe('inbox');
    expect($receipt->filename)->toBeNull();
    expect($receipt->content_hash)->toBe(hash('sha256', '%PDF scan'));
    Storage::disk('local')->assertExists($receipt->local_path);
    Bus::assertChained([ExtractReceipt::class, FileReceipt::class]);
    expect(Receipt::count())->toBe(2);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'download') && str_contains($r->header('Dropbox-API-Arg')[0], 'id:known'));
});

test('a missing inbox folder is created and the scan finds nothing', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response(['error_summary' => 'path/not_found/.'], 409),
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
    ]);

    expect(app(DropboxIntake::class)->scanInbox())->toBe(['new' => 0, 'duplicates' => 0, 'skipped' => 0]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_folder_v2') && $r['path'] === '/Diluno/Receipts/_Inbox');
});

test('a file ernte already has is flagged as a duplicate, not read, and forgotten once removed from the inbox', function () {
    $original = Receipt::create(['original_name' => 'Rechnung 4711.pdf', 'filename' => 'Rechnung 4711.pdf', 'content_hash' => hash('sha256', '%PDF same'), 'original_mime' => 'application/pdf', 'size_bytes' => 1, 'filing_status' => 'filed']);
    fakeDropboxFolder([dbxFile('id:dup', 'Scan.pdf')], ['id:dup' => '%PDF same'], [
        'api.dropboxapi.com/2/files/list_folder' => Http::sequence()
            ->push(['entries' => [dbxFile('id:dup', 'Scan.pdf')], 'has_more' => false])
            ->push(['entries' => [], 'has_more' => false]), // later: Sam deleted it in Dropbox
    ]);

    expect(app(DropboxIntake::class)->scanInbox()['duplicates'])->toBe(1);

    $dup = Receipt::where('dropbox_file_id', 'id:dup')->first();
    expect($dup->duplicate_of_id)->toBe($original->id);
    expect($dup->filing_status)->toBe('failed');
    expect($dup->filing_error)->toContain('Rechnung 4711.pdf');
    expect($dup->isFlagged())->toBeTrue();
    Bus::assertNothingDispatched();

    app(DropboxIntake::class)->scanInbox();
    expect(Receipt::where('dropbox_file_id', 'id:dup')->exists())->toBeFalse();
    expect(Receipt::find($original->id))->not->toBeNull();
});

function inboxReceipt(array $attrs = []): Receipt
{
    Storage::disk('local')->put('receipts/inbox.pdf', '%PDF');

    return Receipt::create($attrs + [
        'source' => 'inbox', 'original_name' => 'Scan 7 Oct 2026.pdf', 'content_hash' => hash('sha256', uniqid()),
        'original_mime' => 'application/pdf', 'size_bytes' => 4, 'local_path' => 'receipts/inbox.pdf',
        'dropbox_file_id' => 'id:scan', 'dropbox_path' => '/Diluno/Receipts/_Inbox/Scan 7 Oct 2026.pdf', 'filing_status' => 'inbox',
        'extraction_status' => 'done', 'vendor' => 'Coop', 'document_date' => '2026-09-14', 'target_year' => 2026, 'target_month' => 9,
    ]);
}

test('a read scan is moved from the inbox into its month under a generated name', function () {
    fakeDropboxFolder([dbxFile('id:scan', 'Scan 7 Oct 2026.pdf')], [], [
        'api.dropboxapi.com/2/files/move_v2' => Http::sequence()
            ->push(['error_summary' => 'to/conflict/file/.'], 409)
            ->push(['metadata' => dbxFile('id:scan', '2026-09-14_Coop_2.pdf', '/Diluno/Receipts/2026_Q3/09')]),
    ]);
    $receipt = inboxReceipt();

    expect(app(ReceiptFiler::class)->file($receipt))->toBeTrue();

    $moves = Http::recorded(fn (Request $r) => str_contains($r->url(), 'move_v2'))->map(fn ($p) => $p[0]['to_path'])->values()->all();
    expect($moves)->toBe(['/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop.pdf', '/Diluno/Receipts/2026_Q3/09/2026-09-14_Coop_2.pdf']);
    $receipt->refresh();
    expect($receipt->filing_status)->toBe('filed');
    expect($receipt->filename)->toBe('2026-09-14_Coop_2.pdf');
    expect($receipt->local_path)->toBeNull();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'files/upload'));
});

test('a file with a real name keeps it, and a clash leaves it in the inbox', function () {
    fakeDropboxFolder([dbxFile('id:scan', 'Swisscom Sept.pdf')], [], [
        'api.dropboxapi.com/2/files/move_v2' => Http::response(['error_summary' => 'to/conflict/file/.'], 409),
    ]);
    $receipt = inboxReceipt(['original_name' => 'Swisscom Sept.pdf']);

    expect(app(ReceiptFiler::class)->file($receipt))->toBeFalse();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'move_v2')))->toHaveCount(1); // no second name is tried
    expect($receipt->fresh()->filing_status)->toBe('failed');
    expect($receipt->fresh()->filing_error)->toContain('still in the inbox');
});

test('a scan without a readable date stays in the inbox until Sam files it', function () {
    fakeDropboxFolder([dbxFile('id:scan', 'Scan 7 Oct 2026.pdf')], [], [
        'api.dropboxapi.com/2/files/move_v2' => Http::response(['metadata' => dbxFile('id:scan', 'Beleg.pdf', '/Diluno/Receipts/2026_Q4/10')]),
    ]);
    $receipt = inboxReceipt(['document_date' => null, 'vendor' => null, 'target_year' => 2026, 'target_month' => 10]);

    expect(app(ReceiptFiler::class)->file($receipt))->toBeFalse();
    expect($receipt->fresh()->filing_status)->toBe('failed');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'move_v2'));

    $receipt->update(['target_edited' => true, 'filing_status' => 'inbox']);
    expect(app(ReceiptFiler::class)->file($receipt->fresh()))->toBeTrue();
});

test('existing files of a quarter are listed, without statements and without known ones', function () {
    Receipt::create(['original_name' => 'x.pdf', 'content_hash' => hash('sha256', 'x'), 'original_mime' => 'application/pdf', 'size_bytes' => 1, 'dropbox_file_id' => 'id:known']);
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => function (Request $r) {
            return match ($r['path']) {
                '/Diluno/Receipts/2026_Q3/07' => Http::response(['entries' => [
                    dbxFile('id:a', '03_Swisscom.pdf', '/Diluno/Receipts/2026_Q3/07'),
                    dbxFile('id:st', '_260731_Kontoauszug.pdf', '/Diluno/Receipts/2026_Q3/07'),
                    dbxFile('id:known', 'x.pdf', '/Diluno/Receipts/2026_Q3/07'),
                    dbxFile('id:py', 'notes.txt', '/Diluno/Receipts/2026_Q3/07'),
                    ['.tag' => 'folder', 'id' => 'id:kk', 'name' => 'Kreditkarte', 'path_display' => '/Diluno/Receipts/2026_Q3/07/Kreditkarte'],
                ], 'has_more' => false]),
                '/Diluno/Receipts/2026_Q3/07/Kreditkarte' => Http::response(['entries' => [dbxFile('id:c', '01_Netlify.pdf', '/Diluno/Receipts/2026_Q3/07/Kreditkarte')], 'has_more' => false]),
                default => Http::response(['error_summary' => 'path/not_found/.'], 409),
            };
        },
    ]);

    $files = app(DropboxIntake::class)->unknownInQuarter(2026, 3);

    expect(array_column($files, 'id'))->toBe(['id:a', 'id:c']);
    expect($files[1])->toMatchArray(['year' => 2026, 'month' => 7]);
});

test('files can be left out of adoption by name pattern', function () {
    Http::fake(['api.dropboxapi.com/2/files/list_folder' => fn (Request $r) => $r['path'] === '/Diluno/Receipts/2026_Q1/02'
        ? Http::response(['entries' => [
            dbxFile('id:a', '05_Rechnung_415896317.pdf', '/Diluno/Receipts/2026_Q1/02'),
            dbxFile('id:s', '14_Lohnabrechnung SA 6000 - Vorlage.pdf', '/Diluno/Receipts/2026_Q1/02'),
            dbxFile('id:t', '10_steuern.pdf', '/Diluno/Receipts/2026_Q1/02'),
        ], 'has_more' => false])
        : Http::response(['error_summary' => 'path/not_found/.'], 409)]);

    $files = app(DropboxIntake::class)->unknownInQuarter(2026, 1, ['*lohnabrechnung*', '*_steuern.pdf']);
    expect(array_column($files, 'id'))->toBe(['id:a']);

    $this->artisan('ernte:receipts:adopt 2026 1 --dry-run --except="*Lohnabrechnung*"')
        ->expectsOutputToContain('Leaving out: *Lohnabrechnung*')
        ->expectsOutputToContain('2 file(s) in 2026 Q1')
        ->assertExitCode(0);
});

test('adopting registers a file where it is, numbered or not, and only queues reading', function () {
    fakeDropboxFolder([dbxFile('id:a', '03_Swisscom.pdf', '/Diluno/Receipts/2026_Q3/07')], ['id:a' => '%PDF swisscom']);

    $receipt = app(DropboxIntake::class)->adopt(['id' => 'id:a', 'name' => '03_Swisscom.pdf', 'path' => '/Diluno/Receipts/2026_Q3/07/03_Swisscom.pdf', 'year' => 2026, 'month' => 7]);

    expect($receipt->source)->toBe('existing');
    expect($receipt->filing_status)->toBe('filed');
    expect($receipt->numberPrefix())->toBe('03');
    expect([$receipt->target_year, $receipt->target_month, $receipt->target_edited])->toBe([2026, 7, true]);
    Bus::assertDispatched(ExtractReceipt::class);
    Bus::assertNotDispatched(FileReceipt::class);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'move_v2') || str_contains($r->url(), 'upload'));
});

test('the adopt command lists on dry run and registers nothing', function () {
    Http::fake(['api.dropboxapi.com/2/files/list_folder' => fn (Request $r) => $r['path'] === '/Diluno/Receipts/2026_Q3/08'
        ? Http::response(['entries' => [dbxFile('id:a', 'Rechnung.pdf', '/Diluno/Receipts/2026_Q3/08')], 'has_more' => false])
        : Http::response(['error_summary' => 'path/not_found/.'], 409)]);

    $this->artisan('ernte:receipts:adopt 2026 3 --dry-run')
        ->expectsOutputToContain('/Diluno/Receipts/2026_Q3/08/Rechnung.pdf')
        ->expectsOutputToContain('1 file(s) in 2026 Q3')
        ->assertExitCode(0);

    expect(Receipt::count())->toBe(0);
});

test('the inbox check does nothing while Dropbox is not connected', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => null]);
    Http::fake();

    $this->artisan('ernte:receipts:check-inbox')->assertExitCode(0);
    Http::assertNothingSent();
});
