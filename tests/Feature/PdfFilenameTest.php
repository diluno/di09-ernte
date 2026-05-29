<?php

use App\Models\BusinessProfile;
use App\Models\Estimate;
use App\Models\Invoice;

function pdfProfile(string $name): void
{
    BusinessProfile::create([
        'name' => $name, 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
    ]);
}

test('invoice pdf filename is prefixed with the slugged business name', function () {
    pdfProfile('Diluno GmbH');
    $invoice = Invoice::factory()->create(['number' => '2026-014']);

    expect($invoice->pdfFilename())->toBe('Diluno-GmbH-Rechnung-2026-014.pdf');
});

test('estimate pdf filename is prefixed with the slugged business name', function () {
    pdfProfile('Diluno GmbH');
    $estimate = Estimate::factory()->create(['number' => 'OF-2026-014']);

    expect($estimate->pdfFilename())->toBe('Diluno-GmbH-Offerte-OF-2026-014.pdf');
});

test('accents and punctuation in the business name are asciified and hyphenated', function () {
    pdfProfile('Müller & Co. AG');
    $invoice = Invoice::factory()->create(['number' => '2026-001']);

    expect($invoice->pdfFilename())->toBe('Muller-Co-AG-Rechnung-2026-001.pdf');
});

test('an empty business name yields no prefix', function () {
    pdfProfile('');
    $invoice = Invoice::factory()->create(['number' => '2026-002']);

    expect($invoice->pdfFilename())->toBe('Rechnung-2026-002.pdf');
});
