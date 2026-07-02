<?php

use App\Mail\InvoiceMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
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

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Mira Okafor', 'is_default' => true]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => '2026-014',
        'status' => 'sent',
        'due_on' => now()->addDays(30)->toDateString(),
        'total_rappen' => 123450,
    ]);

    Storage::disk('local')->put('invoices/2026-014.pdf', '%PDF-test');

    $mail = new InvoiceMail($invoice, 'invoices/2026-014.pdf');
    // Delivered as a plain-text email so the line breaks survive (HTML collapses them).
    $mail->assertSeeInText('Mira Okafor');
    $mail->assertSeeInText('2026-014');
    $mail->assertSeeInText("CHF 1'234.50");
    expect($mail->pdfPath)->toBe('invoices/2026-014.pdf');
    $mail->assertHasAttachment(
        \Illuminate\Mail\Mailables\Attachment::fromPath(Storage::disk('local')->path('invoices/2026-014.pdf'))
            ->as('Ernte-Test-Rechnung-2026-014.pdf')
            ->withMime('application/pdf')
    );
});
