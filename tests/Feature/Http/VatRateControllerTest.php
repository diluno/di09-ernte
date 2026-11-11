<?php

use App\Models\User;
use App\Models\VatRate;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    VatRate::query()->delete();
});

test('index renders the editor with all rates', function () {
    VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->get('/settings/vat-rates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/VatRates')
            ->has('rates', 1));
});

test('store creates a rate', function () {
    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertRedirect();

    expect(VatRate::where('rate', 8.10)->whereDate('valid_from', '2024-01-01')->exists())->toBeTrue();
});

test('update changes a rate', function () {
    $rate = VatRate::create(['rate' => 8.00, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->patch("/settings/vat-rates/{$rate->id}", ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertRedirect();

    expect((float) $rate->fresh()->rate)->toBe(8.10);
});

test('destroy removes a rate', function () {
    $rate = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->delete("/settings/vat-rates/{$rate->id}")->assertRedirect();

    expect(VatRate::find($rate->id))->toBeNull();
});

test('valid_until before valid_from is rejected', function () {
    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-12-31', 'valid_until' => '2024-01-01'])
        ->assertSessionHasErrors('valid_until');

    expect(VatRate::count())->toBe(0);
});

test('overlapping validity windows are rejected', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2023-06-01', 'valid_until' => null])
        ->assertSessionHasErrors('valid_from');

    expect(VatRate::count())->toBe(1);
});

test('the overlap check excludes the row being edited', function () {
    $rate = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->patch("/settings/vat-rates/{$rate->id}", ['rate' => 8.20, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors();

    expect((float) $rate->fresh()->rate)->toBe(8.20);
});

test('adding an open-ended rate after the current open-ended one closes it the day before', function () {
    $current = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->post('/settings/vat-rates', ['rate' => 8.50, 'valid_from' => '2027-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '31 Dec 2026'));

    expect($current->fresh()->valid_until->toDateString())->toBe('2026-12-31');
    expect(VatRate::count())->toBe(2);
    expect(VatRate::rateForDate('2026-12-31'))->toBe(8.10);
    expect(VatRate::rateForDate('2027-01-01'))->toBe(8.50);
});

test('a rate starting on or before the open-ended rate is still rejected and changes nothing', function () {
    $current = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->post('/settings/vat-rates', ['rate' => 8.50, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasErrors('valid_from');

    expect($current->fresh()->valid_until)->toBeNull();
    expect(VatRate::count())->toBe(1);
});

test('a bounded rate inside the open-ended one is rejected rather than closing it', function () {
    $current = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->post('/settings/vat-rates', ['rate' => 2.60, 'valid_from' => '2027-01-01', 'valid_until' => '2027-06-30'])
        ->assertSessionHasErrors('valid_from');

    expect($current->fresh()->valid_until)->toBeNull();
    expect(VatRate::count())->toBe(1);
});

test('editing a rate never closes another open-ended rate', function () {
    $current = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);
    $old = VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    $this->patch("/settings/vat-rates/{$old->id}", ['rate' => 7.70, 'valid_from' => '2027-01-01', 'valid_until' => null])
        ->assertSessionHasErrors('valid_from');

    expect($current->fresh()->valid_until)->toBeNull();
});

test('a new non-overlapping future window is accepted', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors();

    expect(VatRate::count())->toBe(2);
});
