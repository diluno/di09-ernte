<?php

use App\Models\BusinessProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'billing@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
        'reminder_days_after_due' => 7,
    ]);
});

test('GET /settings renders Settings/Profile with the business profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Profile')
            ->where('profile.name', 'Ernte Test')
            ->where('profile.email', 'billing@ernte.test')
            ->where('profile.default_currency', 'CHF'));
});

test('PATCH /settings/profile updates the business profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Updated Studio',
            'address_line_1' => 'Bahnhofstrasse 1',
            'address_line_2' => '',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'ch',
            'uid' => 'CHE-123.456.789',
            'vat_id' => 'CHE-123.456.789 MWST',
            'iban' => 'CH9300762011623852957',
            'qr_iban' => 'CH4431999123000889012',
            'email' => 'hello@example.test',
            'logo_path' => '',
            'default_currency' => 'CHF',
            'default_vat_rate' => 7.70,
            'invoice_number_prefix' => 'ERN',
            'reminder_days_after_due' => 10,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $profile = BusinessProfile::current();
    expect($profile->name)->toBe('Updated Studio');
    expect($profile->country)->toBe('CH');
    expect($profile->email)->toBe('hello@example.test');
    expect((float) $profile->default_vat_rate)->toBe(7.70);
    expect($profile->reminder_days_after_due)->toBe(10);
});

test('invalid profile fields are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => '',
            'country' => 'CHE',
            'email' => 'not-an-email',
            'default_currency' => 'EUR',
            'default_vat_rate' => 101,
            'reminder_days_after_due' => 0,
        ])
        ->assertSessionHasErrors([
            'name',
            'country',
            'email',
            'default_currency',
            'default_vat_rate',
            'reminder_days_after_due',
        ]);
});

test('unauthenticated users cannot access settings', function () {
    $this->get('/settings')->assertRedirect('/login');
    $this->patch('/settings/profile', [])->assertRedirect('/login');
});
