<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\TimeEntry;

test('invoice has many lines and events', function () {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory()->count(3)->create(['invoice_id' => $invoice->id]);
    InvoiceEvent::create(['invoice_id' => $invoice->id, 'kind' => 'created', 'occurred_at' => now()]);

    expect($invoice->fresh()->lines)->toHaveCount(3);
    expect($invoice->fresh()->events)->toHaveCount(1);
});

test('cannot delete a client with invoices (restrictOnDelete)', function () {
    $client = Client::factory()->create();
    Invoice::factory()->create(['client_id' => $client->id]);

    expect(fn () => $client->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('deleting an invoice sets time_entries.invoice_id to NULL', function () {
    $invoice = Invoice::factory()->create();
    $entry = TimeEntry::factory()->create(['invoice_id' => $invoice->id]);

    $invoice->delete();

    expect($entry->fresh()->invoice_id)->toBeNull();
});

test('overdue accessor', function () {
    $i = Invoice::factory()->sent()->create(['due_on' => now()->subDay()->toDateString()]);
    expect($i->overdue)->toBeTrue();

    $i2 = Invoice::factory()->paid()->create();
    expect($i2->overdue)->toBeFalse();
});
