<?php

use App\Models\User;

test('a user can create an api token', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-device')->plainTextToken;

    expect($token)->toBeString()->not->toBeEmpty();
    expect($user->tokens()->count())->toBe(1);
});

test('POST /api/auth/token returns a token for valid credentials', function () {
    $user = User::factory()->create(['email' => 'me@ernte.local']);

    $response = $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'password',
        'device_name' => 'iPhone',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    expect($user->fresh()->tokens()->count())->toBe(1);
});

test('POST /api/auth/token rejects invalid credentials with 422', function () {
    User::factory()->create(['email' => 'me@ernte.local']);

    $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'wrong-password',
        'device_name' => 'iPhone',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('POST /api/auth/token requires device_name', function () {
    $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('device_name');
});

test('DELETE /api/auth/token revokes the current token', function () {
    $user = User::factory()->create();
    // Authenticate with a REAL token (not Sanctum::actingAs, whose transient
    // token has no ->delete()), so currentAccessToken() resolves a deletable row.
    $plain = $user->createToken('iPhone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->deleteJson('/api/auth/token')
        ->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('a token issued by /api/auth/token grants Bearer access to a protected route', function () {
    User::factory()->create(['email' => 'cycle@ernte.local']);

    $token = $this->postJson('/api/auth/token', [
        'email' => 'cycle@ernte.local',
        'password' => 'password',
        'device_name' => 'iPhone',
    ])->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'cycle@ernte.local');
});

test('POST /api/auth/token is rate limited', function () {
    User::factory()->create(['email' => 'me@ernte.local']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/auth/token', [
            'email' => 'me@ernte.local',
            'password' => 'wrong-password',
            'device_name' => 'iPhone',
        ])->assertStatus(422);
    }

    // 6th attempt within the window is throttled (throttle:5,1).
    $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'wrong-password',
        'device_name' => 'iPhone',
    ])->assertStatus(429);
});

function mcpListTools(string $plainToken)
{
    // Each call must authenticate from its own header, not a cached guard user.
    app('auth')->forgetGuards();

    return test()->withToken($plainToken)->postJson(
        '/api/mcp',
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Accept' => 'application/json, text/event-stream'],
    );
}

test('the token endpoint mints a companion token that cannot reach the MCP server', function () {
    $user = User::factory()->create(['email' => 'owner@ernte.test', 'password' => bcrypt('secret-pass')]);

    $plain = $this->postJson('/api/auth/token', [
        'email' => 'owner@ernte.test', 'password' => 'secret-pass', 'device_name' => 'iPhone',
    ])->assertOk()->json('token');

    expect($user->tokens()->sole()->abilities)->toBe(['companion']);

    mcpListTools($plain)->assertForbidden();

    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/me')->assertOk();
});

test('scope "mcp" mints a token the MCP server accepts', function () {
    $user = User::factory()->create(['email' => 'owner@ernte.test', 'password' => bcrypt('secret-pass')]);

    $plain = $this->postJson('/api/auth/token', [
        'email' => 'owner@ernte.test', 'password' => 'secret-pass', 'device_name' => 'claude-mcp', 'scope' => 'mcp',
    ])->assertOk()->json('token');

    expect($user->tokens()->sole()->abilities)->toBe(['mcp']);

    $names = collect(mcpListTools($plain)->assertOk()->json('result.tools'))->pluck('name');
    expect($names)->toContain('send_invoice');
});

test('an unknown scope is rejected', function () {
    User::factory()->create(['email' => 'owner@ernte.test', 'password' => bcrypt('secret-pass')]);

    $this->postJson('/api/auth/token', [
        'email' => 'owner@ernte.test', 'password' => 'secret-pass', 'device_name' => 'x', 'scope' => '*',
    ])->assertJsonValidationErrors('scope');
});

test('tokens minted before scopes existed (abilities *) keep working for the API and MCP', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('legacy')->plainTextToken;

    expect($user->tokens()->sole()->abilities)->toBe(['*']);

    mcpListTools($plain)->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/me')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/timer')->assertOk();
});
