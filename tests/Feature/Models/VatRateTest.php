<?php

use App\Models\BusinessProfile;
use App\Models\VatRate;

beforeEach(function () {
    VatRate::query()->delete();
});

test('forDate returns the row whose window covers the date', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);
    VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    expect((float) VatRate::forDate('2020-06-01')->rate)->toBe(7.70);
    expect((float) VatRate::forDate('2025-06-01')->rate)->toBe(8.10);
    expect(VatRate::rateForDate('2024-06-01'))->toBe(8.10);
});

test('forDate picks the newest valid_from when multiple cover the date', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => null]);
    VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    expect((float) VatRate::forDate('2025-01-01')->rate)->toBe(8.10);
});

test('forDate falls back to the business profile default when no row covers the date', function () {
    BusinessProfile::query()->delete();
    BusinessProfile::create(['name' => 'T', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 6.50]);

    $rate = VatRate::forDate('2025-01-01');

    expect($rate->exists)->toBeFalse();
    expect((float) $rate->rate)->toBe(6.50);
});
