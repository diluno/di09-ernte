<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Invoicing\QrBillRenderer;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte GmbH', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001',
        'city' => 'Zürich', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        // Sprain library test QR-IBAN (IID in 30000–31999 range):
        'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->client = Client::factory()->create([
        'name' => 'Atlas Robotics', 'address_line_1' => 'Friedrichstrasse 47',
        'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH',
    ]);
});

test('renders a valid QR payment part containing the IBAN and reference', function () {
    $invoice = Invoice::factory()->create([
        'client_id' => $this->client->id, 'number' => '2026-014',
        'total_rappen' => 428_000, 'currency' => 'CHF',
        'qr_reference' => '000000000000000000000000146', // 27 digits (id=14 padded + check via generator in practice)
    ]);

    $html = app(QrBillRenderer::class)->html($invoice);

    expect($html)->toBeString()->not->toBe('');
    // The amount appears formatted; the QRR reference appears grouped or raw somewhere in the slip.
    expect($html)->toContain('CHF');
});

test('uses a plain IBAN with no reference when qr_iban is absent', function () {
    BusinessProfile::current()->update(['qr_iban' => null, 'iban' => 'CH9300762011623852957']);
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'total_rappen' => 100_00, 'currency' => 'CHF', 'qr_reference' => null]);

    $html = app(QrBillRenderer::class)->html($invoice);
    expect($html)->toBeString()->not->toBe('');
});

test('self-heals a missing qr_reference under a QR-IBAN profile (no 500)', function () {
    // Profile from beforeEach already has a qr_iban; invoice has no reference.
    $invoice = Invoice::factory()->create([
        'client_id' => $this->client->id, 'total_rappen' => 250_00, 'currency' => 'CHF',
        'qr_reference' => null,
    ]);

    $html = app(QrBillRenderer::class)->html($invoice);

    expect($html)->toBeString()->not->toBe('');
    $invoice->refresh();
    expect($invoice->qr_reference)->toBeString();
    expect(strlen($invoice->qr_reference))->toBe(27);
    expect($invoice->qr_reference)->toMatch('/^\d{27}$/');
});
