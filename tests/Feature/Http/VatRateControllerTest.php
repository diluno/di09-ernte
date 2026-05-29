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

test('a new non-overlapping future window is accepted', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors();

    expect(VatRate::count())->toBe(2);
});
