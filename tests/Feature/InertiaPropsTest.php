<?php

use App\Models\Backup;
use App\Models\BusinessProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('system uptime is non-zero on a real request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('system.uptime_seconds', fn (int $u) => $u > 0)
        );
});

test('shared props include user settings and system info', function () {
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
            ->has('system.db_driver')
            ->has('system.db_version')
            ->has('system.uptime_seconds')
        );
});

test('system shared prop includes db_size_bytes and backup_last_at', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('system.db_size_bytes')
            ->has('system.backup_last_at')   // nullable, but key present
        );
});

test('backup_last_at reflects the latest backup row', function () {
    $user = User::factory()->create();
    Backup::create([
        'path' => '/tmp/x.tgz',
        'size_bytes' => 1024,
        'created_at' => now()->subHours(3),
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('system.backup_last_at', fn ($v) => $v !== null)
        );
});
