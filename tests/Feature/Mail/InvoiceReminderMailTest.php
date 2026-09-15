<?php

use App\Mail\InvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

test('reminder mail renders invoice details, copies the operator and attaches the stored pdf', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'billing@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Mira Okafor', 'is_default' => true]);
    Storage::disk('local')->put('invoices/2026-014.pdf', '%PDF-test');
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => '2026-014',
        'status' => 'sent',
        'due_on' => now()->subDays(10)->toDateString(),
        'total_rappen' => 123450,
        'pdf_path' => 'invoices/2026-014.pdf',
    ]);

    $mail = new InvoiceReminderMail($invoice);
    $mail->assertSeeInHtml('Mira Okafor');
    $mail->assertSeeInHtml("CHF 1'234.50");
    $mail->assertSeeInText('2026-014');
    $mail->assertHasBcc('billing@ernte.test');
    $mail->assertHasAttachment(
        \Illuminate\Mail\Mailables\Attachment::fromPath(Storage::disk('local')->path('invoices/2026-014.pdf'))
            ->as('Rechnung-2026-014.pdf')
            ->withMime('application/pdf')
    );
});
