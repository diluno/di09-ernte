<?php

use App\Mail\InvoiceMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

test('invoice mail renders invoice details and attaches the pdf path', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'billing@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);

    $client = Client::factory()->create(['contact_name' => 'Mira Okafor']);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => '2026-014',
        'status' => 'sent',
        'due_on' => now()->addDays(30)->toDateString(),
        'total_rappen' => 123450,
    ]);

    Storage::disk('local')->put('invoices/2026-014.pdf', '%PDF-test');

    $mail = new InvoiceMail($invoice, 'invoices/2026-014.pdf');
    $html = $mail->render();

    expect($html)->toContain('Mira Okafor');
    expect($html)->toContain('2026-014');
    expect($html)->toContain("CHF 1'234.50");
    expect($mail->pdfPath)->toBe('invoices/2026-014.pdf');
});
