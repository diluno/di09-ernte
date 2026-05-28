<?php

use App\Models\BusinessProfile;
use Database\Seeders\BootstrapSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('BusinessProfile::current returns the singleton row', function () {
    BusinessProfile::create(['name' => 'Acme', 'country' => 'CH', 'default_currency' => 'CHF']);

    expect(BusinessProfile::current())
        ->toBeInstanceOf(BusinessProfile::class)
        ->name->toBe('Acme');
});

test('BusinessProfile::current throws when no row exists', function () {
    BusinessProfile::query()->delete();

    expect(fn () => BusinessProfile::current())
        ->toThrow(ModelNotFoundException::class);
});

test('seeder creates a single BusinessProfile row', function () {
    $this->seed(BootstrapSeeder::class);

    expect(BusinessProfile::count())->toBe(1);
});

test('seeder does not overwrite an existing BusinessProfile row', function () {
    BusinessProfile::forceCreate([
        'id' => 1,
        'name' => 'Configured in Settings',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $this->seed(BootstrapSeeder::class);

    expect(BusinessProfile::count())->toBe(1)
        ->and(BusinessProfile::current()->name)->toBe('Configured in Settings');
});
