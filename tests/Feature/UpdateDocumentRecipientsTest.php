<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\User;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->actingAs(User::factory()->create());
});

it('updates invoice recipients on a draft', function () {
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->for($client)->create(['status' => 'draft', 'recipients' => []]);

    $this->patch("/invoices/{$invoice->id}", [
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
    ])->assertRedirect();

    expect($invoice->fresh()->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('updates estimate recipients on a draft', function () {
    $client = Client::factory()->create();
    $estimate = Estimate::factory()->for($client)->create(['status' => 'draft', 'recipients' => []]);

    $this->patch("/estimates/{$estimate->id}", [
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
    ])->assertRedirect();

    expect($estimate->fresh()->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('updates recurring invoice recipients', function () {
    $client = Client::factory()->create();
    $schedule = RecurringInvoice::factory()->for($client)->create(['cadence' => 'monthly', 'recipients' => []]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->patch("/recurring-invoices/{$schedule->id}", [
        'client_id' => $schedule->client_id,
        'cadence' => 'monthly',
        'next_run_on' => '2026-08-01',
        'vat_rate' => 8.10,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000]],
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
    ])->assertRedirect();

    expect($schedule->fresh()->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});
