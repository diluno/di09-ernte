<?php

use App\Models\User;
use Database\Seeders\BootstrapSeeder;
use Illuminate\Support\Facades\Hash;

test('the first seed refuses to create the owner without a password', function () {
    config(['ernte.owner.password' => '']);

    expect(fn () => $this->seed(BootstrapSeeder::class))->toThrow(RuntimeException::class, 'ERNTE_USER_PASSWORD is empty');
    expect(User::count())->toBe(0);
});

test('the owner is created with the configured password, never a built-in one', function () {
    config(['ernte.owner' => ['email' => 'owner@example.com', 'name' => 'Owner', 'password' => 'a long secret']]);

    $this->seed(BootstrapSeeder::class);

    $user = User::where('email', 'owner@example.com')->firstOrFail();
    expect(Hash::check('a long secret', $user->password))->toBeTrue();
    expect(Hash::check('changeme', $user->password))->toBeFalse();
});

test('later seeds need no password and leave the existing owner alone', function () {
    config(['ernte.owner' => ['email' => 'owner@example.com', 'name' => 'Owner', 'password' => 'a long secret']]);
    $this->seed(BootstrapSeeder::class);
    $hash = User::first()->password;

    config(['ernte.owner.password' => null]);
    $this->seed(BootstrapSeeder::class);

    expect(User::count())->toBe(1);
    expect(User::first()->password)->toBe($hash);
});
