<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\EstimateCounter;
use App\Services\Harvest\CounterReconciler;

beforeEach(function () {
    $this->client = Client::factory()->create();
});

test('bumps the invoice counter to the max matching suffix per year', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2025-007']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2025-003']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-001']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => 'LEGACY-999']); // ignored

    CounterReconciler::reconcileInvoices();

    expect(InvoiceCounter::find(2025)->last_n)->toBe(7);
    expect(InvoiceCounter::find(2026)->last_n)->toBe(1);
});

test('bumps the estimate counter for OF-prefixed numbers only', function () {
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2025-012']);
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'EST-555']); // ignored

    CounterReconciler::reconcileEstimates();

    expect(EstimateCounter::find(2025)->last_n)->toBe(12);
});

test('never lowers an existing counter', function () {
    EstimateCounter::create(['year' => 2025, 'last_n' => 99]);
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2025-003']);

    CounterReconciler::reconcileEstimates();

    expect(EstimateCounter::find(2025)->last_n)->toBe(99);
});
