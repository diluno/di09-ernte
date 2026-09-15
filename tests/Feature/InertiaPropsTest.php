<?php

use App\Models\BusinessProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared props include user settings, app and business info', function () {
    BusinessProfile::create([
        'name' => 'Diluno GmbH',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $user = User::factory()->create([
        'settings' => ['theme' => 'dark', 'density' => 'compact', 'accent' => '#c97b3c'],
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.settings.theme', 'dark')
            ->where('auth.user.settings.density', 'compact')
            ->has('app.version')
            ->has('app.host')
            ->has('app.port')
            ->where('business.name', 'Diluno GmbH')
            ->missing('system')
        );
});
