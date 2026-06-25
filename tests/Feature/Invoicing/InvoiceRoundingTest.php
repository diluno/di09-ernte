<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Invoicing\QrBillRenderer;
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
    BusinessProfile::create([
        'name' => 'Ernte GmbH', 'address_line_1' => 'Bahnhofstrasse 1',
        'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'qr_iban' => 'CH4431999123000889012',
    ]);
    $client = Client::factory()->create([
        'name' => 'Test Client', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH',
    ]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'currency' => 'CHF',
        'vat_rate' => 8.10,
    ]);

    $html = app(QrBillRenderer::class)->html($invoice);

    // The renderer sets amount = round(total_rappen / 100, 2) = 313.50.
    // The Sprain library formats it as number_format(313.50, 2, '.', ' ') = "313.50".
    expect($html)->toContain('313.50');
    // Must NOT contain the unrounded subtotal+vat sum (290.00 + 23.49 = 313.49)
    // or 311.49, proving total_rappen (not a recomputed figure) is used.
    expect($html)->not->toContain('313.49');
});
