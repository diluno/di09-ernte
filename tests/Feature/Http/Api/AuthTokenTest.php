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
