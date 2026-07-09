<?php

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    BusinessProfile::firstOrCreate(['id' => 1], [
        'name' => 'Ernte Test',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);
});

test('GET /invoices/export downloads a zip of invoices paid in the quarter', function () {
    Storage::fake('local');

    $inQuarter = Invoice::factory()->create([
        'status' => 'paid', 'paid_at' => '2026-05-10 12:00:00', 'pdf_path' => 'invoices/2026-001.pdf',
    ]);
    Storage::disk('local')->put('invoices/2026-001.pdf', '%PDF-fake');

    // Paid outside the quarter → excluded.
    Invoice::factory()->create(['status' => 'paid', 'paid_at' => '2026-02-01 12:00:00']);
    // Unpaid → excluded.
    Invoice::factory()->create(['status' => 'sent', 'paid_at' => null]);

    $response = $this->get('/invoices/export?year=2026&quarter=2');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('Rechnungen-2026-Q2.zip');

    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    expect($zip->numFiles)->toBe(1)
        ->and($zip->getNameIndex(0))->toBe($inQuarter->pdfFilename());
    $zip->close();
});

test('GET /invoices/export redirects back with an error when the quarter is empty', function () {
    $this->from('/invoices')
        ->get('/invoices/export?year=2019&quarter=1')
        ->assertRedirect('/invoices')
        ->assertSessionHas('error');
});
