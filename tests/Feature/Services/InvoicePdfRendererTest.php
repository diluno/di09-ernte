<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Invoicing\InvoicePdfRenderer;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
    ]);
});

test('html renders markdown and line breaks in a line description', function () {
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);
    InvoiceLine::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => "Design phase\nincluding:\n\n- wireframes\n- **hi-fi** mockups",
    ]);

    $html = app(InvoicePdfRenderer::class)->html($invoice);

    expect($html)->toContain('Design phase<br>');
    expect($html)->toContain('<li>wireframes</li>');
    expect($html)->toContain('<strong>hi-fi</strong>');
});

test('html keeps the qr payment section together for pdf pagination', function () {
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);

    $html = app(InvoicePdfRenderer::class)->html($invoice);

    expect($html)->toContain('break-inside: avoid');
    expect($html)->toContain('page-break-inside: avoid');
});
