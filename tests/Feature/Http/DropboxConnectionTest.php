<?php

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    Cache::forget('dropbox.access_token');
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

test('connect redirects to Dropbox asking for an offline token and the file scopes', function () {
    $response = $this->get('/settings/dropbox/connect');

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith('https://www.dropbox.com/oauth2/authorize?');
    expect($query)->toMatchArray(['client_id' => 'key', 'response_type' => 'code', 'token_access_type' => 'offline']);
    expect($query['redirect_uri'])->toEndWith('/settings/dropbox/callback');
    expect($query['scope'])->toContain('files.content.write');
    expect($query['state'])->toBe(session('dropbox_oauth_state'));
});

test('connect explains itself when the app is not configured', function () {
    config(['services.dropbox.app_key' => null]);

    $this->get('/settings/dropbox/connect')->assertRedirect('/settings')->assertSessionHas('error');
});

test('callback with the right state stores the refresh token and the account', function () {
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 14400, 'refresh_token' => 'refresh-1']),
        'api.dropboxapi.com/2/users/get_current_account' => Http::response(['email' => 'sam@example.com']),
    ]);

    $this->withSession(['dropbox_oauth_state' => 'abc'])
        ->get('/settings/dropbox/callback?state=abc&code=the-code')
        ->assertRedirect('/settings')->assertSessionHas('success');

    $profile = BusinessProfile::current();
    expect($profile->dropbox_refresh_token)->toBe('refresh-1');
    expect($profile->dropbox_account_label)->toBe('sam@example.com');
    expect($profile->dropbox_connected_at)->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2/token')
        && $r['code'] === 'the-code' && $r['grant_type'] === 'authorization_code'
        && str_ends_with($r['redirect_uri'], '/settings/dropbox/callback'));
});

test('callback with a wrong or missing state stores nothing', function (array $session, string $query) {
    Http::fake();

    $this->withSession($session)->get("/settings/dropbox/callback?{$query}")
        ->assertRedirect('/settings')->assertSessionHas('error');

    expect(BusinessProfile::current()->dropbox_refresh_token)->toBeNull();
    Http::assertNothingSent();
})->with([
    'wrong state' => [['dropbox_oauth_state' => 'abc'], 'state=xyz&code=c'],
    'no state in session' => [[], 'state=abc&code=c'],
    'user cancelled' => [['dropbox_oauth_state' => 'abc'], 'state=abc&error=access_denied'],
]);

test('disconnect revokes the token and clears the connection', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => 'refresh-1', 'dropbox_account_label' => 'sam@example.com', 'dropbox_connected_at' => now()]);
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 14400]),
        'api.dropboxapi.com/2/auth/token/revoke' => Http::response(null),
    ]);

    $this->post('/settings/dropbox/disconnect')->assertRedirect('/settings');

    expect(BusinessProfile::current()->dropbox_refresh_token)->toBeNull();
    expect(BusinessProfile::current()->dropbox_account_label)->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'token/revoke'));
});

test('settings shows the connection and whether the root folder exists', function () {
    BusinessProfile::current()->update(['dropbox_refresh_token' => 'refresh-1', 'dropbox_account_label' => 'sam@example.com', 'dropbox_connected_at' => now()]);
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 14400]),
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'folder', 'name' => 'Receipts', 'path_display' => '/Diluno/Receipts']),
    ]);

    $this->get('/settings')->assertInertia(fn (Assert $page) => $page
        ->where('dropbox.configured', true)
        ->where('dropbox.connected', true)
        ->where('dropbox.account', 'sam@example.com')
        ->where('dropbox.root', '/Diluno/Receipts')
        ->where('dropbox.root_ok', true)
        ->missing('profile.dropbox_refresh_token'));
});

test('settings still renders when Dropbox is unreachable or not connected', function () {
    $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dropbox.connected', false)->where('dropbox.root_ok', null));

    BusinessProfile::current()->update(['dropbox_refresh_token' => 'refresh-1']);
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dropbox.connected', true)->where('dropbox.root_ok', null)->has('dropbox.problem'));
});
