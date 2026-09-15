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
        'title' => 'Website Relaunch',
        'status' => 'sent',
        'due_on' => now()->addDays(30)->toDateString(),
        'total_rappen' => 123450,
    ]);

    Storage::disk('local')->put('invoices/2026-014.pdf', '%PDF-test');

    $mail = new InvoiceMail($invoice, 'invoices/2026-014.pdf');
    // HTML body plus a plain-text alternative; both must carry the facts.
    $mail->assertSeeInHtml('Mira Okafor');
    $mail->assertSeeInHtml('Website Relaunch');
    $mail->assertSeeInText('Website Relaunch');
    expect($mail->build()->subject)->toBe('Rechnung 2026-014 - Website Relaunch - Ernte Test');
    $mail->assertSeeInHtml("CHF 1'234.50");
    $mail->assertSeeInText('Mira Okafor');
    $mail->assertSeeInText('2026-014');
    $mail->assertSeeInText("CHF 1'234.50");
    $mail->assertHasBcc('billing@ernte.test');
    expect($mail->pdfPath)->toBe('invoices/2026-014.pdf');
    $mail->assertHasAttachment(
        \Illuminate\Mail\Mailables\Attachment::fromPath(Storage::disk('local')->path('invoices/2026-014.pdf'))
            ->as('Ernte-Test-Rechnung-2026-014.pdf')
            ->withMime('application/pdf')
    );
});

test('invoice mail signs off with the sender name when set', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'sender_name' => 'Samuel Alder',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);

    $invoice = Invoice::factory()->create(['number' => '2026-015']);
    Storage::disk('local')->put('invoices/2026-015.pdf', '%PDF-test');

    $mail = new InvoiceMail($invoice, 'invoices/2026-015.pdf');

    $mail->assertSeeInHtml('Freundliche Grüsse<br>Samuel Alder<br>Ernte Test', false);
    $mail->assertSeeInText("Samuel Alder\nErnte Test");
});
