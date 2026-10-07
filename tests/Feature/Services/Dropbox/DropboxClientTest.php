<?php

use App\Models\BusinessProfile;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxException;
use App\Services\Dropbox\DropboxNotConnected;
use App\Services\Dropbox\DropboxNotFound;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::forget('dropbox.access_token');
    Http::preventStrayRequests();
    $this->dropbox = app(DropboxClient::class);
});

function fakeToken(string $token = 'access-1'): array
{
    return ['api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => $token, 'expires_in' => 14400])];
}

test('paths outside the receipts root are refused before any request', function (string $path) {
    Http::fake();

    expect(fn () => $this->dropbox->upload($path, 'x'))->toThrow(DropboxException::class);
    expect(fn () => $this->dropbox->createFolder($path))->toThrow(DropboxException::class);
    Http::assertNothingSent();
})->with([
    '/Other/file.pdf',
    '/Diluno/ReceiptsEvil/file.pdf',
    '/Diluno/Receipts/../Private/file.pdf',
    '/Diluno',
    '/',
]);

test('paths inside the root pass, whatever their case', function () {
    expect($this->dropbox->guard('/Diluno/Receipts'))->toBe('/Diluno/Receipts');
    expect($this->dropbox->guard('/diluno/receipts/2026_Q3/09/a.pdf'))->toBe('/diluno/receipts/2026_Q3/09/a.pdf');
    expect($this->dropbox->guard('Diluno/Receipts/2026_Q3/'))->toBe('/Diluno/Receipts/2026_Q3');
});

test('an empty root is refused outright', function () {
    config(['services.dropbox.receipts_root' => '']);

    expect(fn () => $this->dropbox->guard('/anything'))->toThrow(DropboxException::class);
});

test('upload adds without overwriting and returns the file id', function () {
    Http::fake(fakeToken() + [
        'content.dropboxapi.com/2/files/upload' => Http::response(['id' => 'id:abc', 'name' => 'Rechnung Müller.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/Rechnung Müller.pdf']),
    ]);

    $entry = $this->dropbox->upload('/Diluno/Receipts/2026_Q3/09/Rechnung Müller.pdf', '%PDF');

    expect($entry)->toMatchArray(['id' => 'id:abc', 'is_folder' => false]);
    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/files/upload')) {
            return false;
        }
        $header = $request->header('Dropbox-API-Arg')[0];
        $arg = json_decode($header, true);

        return $request->hasHeader('Authorization', 'Bearer access-1')
            && $arg['mode'] === 'add' && $arg['autorename'] === false
            && $arg['path'] === '/Diluno/Receipts/2026_Q3/09/Rechnung Müller.pdf'
            && mb_check_encoding($header, 'ASCII')
            && $request->body() === '%PDF';
    });
});

test('a name conflict and a missing file map to their own exceptions', function () {
    Http::fake(fakeToken() + [
        'content.dropboxapi.com/2/files/upload' => Http::response(['error_summary' => 'path/conflict/file/..'], 409),
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['error_summary' => 'path/not_found/.'], 409),
    ]);

    expect(fn () => $this->dropbox->upload('/Diluno/Receipts/a.pdf', 'x'))->toThrow(DropboxConflict::class);
    expect(fn () => $this->dropbox->metadata('id:gone'))->toThrow(DropboxNotFound::class);
    expect($this->dropbox->exists('/Diluno/Receipts/nope'))->toBeFalse();
});

test('the access token is cached, and refreshed once after a 401', function () {
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'old', 'expires_in' => 14400])
            ->push(['access_token' => 'new', 'expires_in' => 14400]),
        'api.dropboxapi.com/2/files/get_metadata' => Http::sequence()
            ->push(['.tag' => 'folder', 'name' => 'Receipts', 'path_display' => '/Diluno/Receipts'])
            ->push(['error_summary' => 'expired_access_token/'], 401)
            ->push(['.tag' => 'folder', 'name' => 'Receipts', 'path_display' => '/Diluno/Receipts']),
    ]);

    $this->dropbox->metadata('/Diluno/Receipts');
    $this->dropbox->metadata('/Diluno/Receipts');

    Http::assertSentCount(5); // token, metadata, metadata(401), token, metadata
    expect(Cache::get('dropbox.access_token'))->toBe('new');
});

test('a file id that resolves outside the root is refused, also for move and download', function () {
    Http::fake(fakeToken() + [
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:x', 'name' => 'tax.pdf', 'path_display' => '/Private/tax.pdf']),
    ]);

    expect(fn () => $this->dropbox->metadata('id:x'))->toThrow(DropboxException::class);
    expect(fn () => $this->dropbox->move('id:x', '/Diluno/Receipts/tax.pdf'))->toThrow(DropboxException::class);
    expect(fn () => $this->dropbox->download('id:x'))->toThrow(DropboxException::class);
    expect(fn () => $this->dropbox->temporaryLink('id:x'))->toThrow(DropboxException::class);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'move_v2') || str_contains($r->url(), 'download') || str_contains($r->url(), 'temporary_link'));
});

test('move looks the file up by id first and never autorenames', function () {
    Http::fake(fakeToken() + [
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:x', 'name' => 'a.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/08/a.pdf']),
        'api.dropboxapi.com/2/files/move_v2' => Http::response(['metadata' => ['.tag' => 'file', 'id' => 'id:x', 'name' => 'a.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/a.pdf']]),
    ]);

    $entry = $this->dropbox->move('id:x', '/Diluno/Receipts/2026_Q3/09/a.pdf');

    expect($entry['path'])->toBe('/Diluno/Receipts/2026_Q3/09/a.pdf');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'move_v2')
        && $r['from_path'] === 'id:x' && $r['autorename'] === false);
});

test('createFolder treats an existing folder as success', function () {
    Http::fake(fakeToken() + [
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['error_summary' => 'path/conflict/folder/.'], 409),
    ]);

    $this->dropbox->createFolder('/Diluno/Receipts/2026_Q4');
    expect(true)->toBeTrue();
});

test('without a connection every call says so', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => null]);
    Http::fake();

    expect($this->dropbox->isConnected())->toBeFalse();
    expect(fn () => $this->dropbox->metadata('/Diluno/Receipts'))->toThrow(DropboxNotConnected::class);
});

test('the refresh token is stored encrypted and never serialised', function () {
    expect(DB::table('business_profile')->value('dropbox_refresh_token'))->not->toContain('refresh-1');
    expect(BusinessProfile::current()->dropbox_refresh_token)->toBe('refresh-1');
    expect(BusinessProfile::current()->toArray())->not->toHaveKey('dropbox_refresh_token');
});

test('the client has no way to delete or overwrite', function () {
    $methods = array_map('strtolower', get_class_methods(DropboxClient::class));

    expect($methods)->not->toContain('delete')->not->toContain('overwrite');
});
