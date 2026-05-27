<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared props include user settings and system info', function () {
    $user = User::factory()->create([
        'settings' => ['theme' => 'dark', 'density' => 'compact', 'accent' => '#c97b3c'],
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.settings.theme', 'dark')
            ->where('auth.user.settings.density', 'compact')
            ->has('app.version')
            ->has('app.port')
            ->has('system.db_driver')
            ->has('system.db_version')
            ->has('system.uptime_seconds')
        );
});
