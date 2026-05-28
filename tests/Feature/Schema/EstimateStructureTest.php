<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\EstimateLine;
use App\Models\Invoice;

test('estimate has many lines and events', function () {
    $estimate = Estimate::factory()->create();
    EstimateLine::factory()->count(3)->create(['estimate_id' => $estimate->id]);
    EstimateEvent::create(['estimate_id' => $estimate->id, 'kind' => 'created', 'occurred_at' => now()]);

    expect($estimate->fresh()->lines)->toHaveCount(3);
    expect($estimate->fresh()->events)->toHaveCount(1);
});

test('cannot delete a client with estimates (restrictOnDelete)', function () {
    $client = Client::factory()->create();
    Estimate::factory()->create(['client_id' => $client->id]);

    expect(fn () => $client->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('deleting a converted invoice nulls converted_invoice_id', function () {
    $invoice = Invoice::factory()->create();
    $estimate = Estimate::factory()->create(['converted_invoice_id' => $invoice->id]);

    $invoice->delete();

    expect($estimate->fresh()->converted_invoice_id)->toBeNull();
});

test('expired accessor is true only for past-due sent estimates', function () {
    $expired = Estimate::factory()->sent()->create(['valid_until' => now()->subDay()->toDateString()]);
    expect($expired->expired)->toBeTrue();

    $live = Estimate::factory()->sent()->create(['valid_until' => now()->addDay()->toDateString()]);
    expect($live->expired)->toBeFalse();

    $accepted = Estimate::factory()->accepted()->create(['valid_until' => now()->subDay()->toDateString()]);
    expect($accepted->expired)->toBeFalse(); // accepted is terminal, never "expired"
});

test('hours accessor sums line hours', function () {
    $estimate = Estimate::factory()->create();
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 1.5]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 2.25]);

    expect($estimate->fresh()->hours)->toBe(3.75);
});
