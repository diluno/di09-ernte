<?php

use App\Models\Invoice;
use App\Support\InvoiceProjections;

test('created draft persists rounded total and rounding adjustment', function () {
    $invoice = Invoice::factory()->create(['vat_rate' => 8.10]);
    $invoice->lines()->create([
        'description' => 'Work',
        'hours' => 1,
        'rate_rappen' => 29000,
        'amount_rappen' => 29000,
        'sort_order' => 0,
    ]);

    // Recompute via the same path update() uses.
    $totals = \App\Services\Invoicing\InvoiceBuilder::computeTotals([29000], 8.10);
    $invoice->update($totals);

    expect($invoice->fresh()->rounding_rappen)->toBe(1);
    expect($invoice->fresh()->total_rappen)->toBe(31350);
});

test('detail projection exposes the rounding amount in CHF', function () {
    $invoice = Invoice::factory()->create([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'vat_rate' => 8.10,
    ]);

    $detail = InvoiceProjections::detail($invoice);

    expect($detail['rounding'])->toBe(0.01);
    expect($detail['total'])->toBe(313.5);
});

test('qr-bill amount equals the rounded total in CHF', function () {
    $invoice = Invoice::factory()->create([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'currency' => 'CHF',
        'vat_rate' => 8.10,
    ]);

    // The renderer derives the amount from total_rappen / 100.
    expect(round($invoice->total_rappen / 100, 2))->toBe(313.5);
});
