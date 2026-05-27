<?php

use App\Models\BusinessProfile;

test('BusinessProfile::current returns the singleton row', function () {
    BusinessProfile::create(['name' => 'Acme', 'country' => 'CH', 'default_currency' => 'CHF']);

    expect(BusinessProfile::current())
        ->toBeInstanceOf(BusinessProfile::class)
        ->name->toBe('Acme');
});

test('BusinessProfile::current throws when no row exists', function () {
    BusinessProfile::query()->delete();

    expect(fn () => BusinessProfile::current())
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('seeder creates a single BusinessProfile row', function () {
    $this->seed(\Database\Seeders\BootstrapSeeder::class);

    expect(BusinessProfile::count())->toBe(1);
});
