<?php

use App\Models\User;
use Database\Seeders\BootstrapSeeder;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\get;

test('registration route is removed', function () {
    get('/register')->assertNotFound();
});

test('seeded user can log in and lands on /projects', function () {
    $this->seed(BootstrapSeeder::class);

    $this->post('/login', [
        'email' => env('ERNTE_USER_EMAIL', 'owner@ernte.local'),
        'password' => env('ERNTE_USER_PASSWORD', 'changeme'),
    ])->assertRedirect('/projects');
});

test('bootstrap seeding does not overwrite an existing account', function () {
    $email = env('ERNTE_USER_EMAIL', 'owner@ernte.local');
    $user = User::factory()->create([
        'email' => $email,
        'name' => 'Configured Owner',
        'password' => Hash::make('user-chosen-password'),
        'settings' => ['theme' => 'dark', 'density' => 'compact'],
    ]);

    $this->seed(BootstrapSeeder::class);

    $user->refresh();
    expect($user->name)->toBe('Configured Owner')
        ->and(Hash::check('user-chosen-password', $user->password))->toBeTrue()
        ->and($user->settings)->toBe(['theme' => 'dark', 'density' => 'compact']);
});
