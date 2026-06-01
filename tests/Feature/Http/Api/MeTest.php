<?php

use App\Models\BusinessProfile;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/me requires authentication', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

test('GET /api/me returns the user and business basics', function () {
    BusinessProfile::create([
        'name' => 'Diluno GmbH',
        'default_currency' => 'CHF',
    ]);
    $user = User::factory()->create(['name' => 'Sam', 'email' => 'sam@ernte.local']);
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJson([
            'user' => ['name' => 'Sam', 'email' => 'sam@ernte.local'],
            'business' => ['name' => 'Diluno GmbH', 'currency' => 'CHF'],
        ]);
});

test('GET /api/me tolerates a missing business profile', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJson(['business' => null]);
});
