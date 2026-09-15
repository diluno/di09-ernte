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

test('html uses the shared sheet: window address with contact, mono meta, terms from dates, no repeated CHF', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics', 'address_line_1' => 'Weg 1', 'postal_code' => '8000', 'city' => 'Zürich']);
    \App\Models\Contact::factory()->for($client)->create(['name' => 'Nina Bärtschi', 'email' => 'nina@example.test', 'is_default' => true]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id, 'number' => '2026-077', 'title' => 'Brand guidelines',
        'issued_on' => '2026-08-14', 'due_on' => '2026-09-13',
    ]);
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Konzept', 'hours' => 6, 'rate_rappen' => 14800, 'amount_rappen' => 88800, 'vat_exempt' => true]);

    $html = app(InvoicePdfRenderer::class)->html($invoice);

    expect($html)->toContain('z.Hd. Nina Bärtschi');
    expect($html)->toContain('Ernte Test · Bahnhofstrasse 1 · 8001 Zürich'); // sender line above the window
    expect($html)->toContain('Zahlbar bis <b>13.09.2026</b> (30 Tage)');
    expect($html)->toContain('<h1>Brand guidelines</h1>');
    expect($html)->toContain('888.00<span class="exempt-mark">*</span>');
    expect($html)->toContain('* ohne MwSt');
    expect($html)->not->toContain('CHF 888.00');
    expect($html)->toContain('Total CHF');
});

test('html omits the z.Hd. line when the recipient is the company itself', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    \App\Models\Contact::factory()->for($client)->create(['name' => 'Atlas Robotics', 'email' => 'billing@example.test', 'is_default' => true]);
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);

    expect(app(InvoicePdfRenderer::class)->html($invoice))->not->toContain('z.Hd.');
});
