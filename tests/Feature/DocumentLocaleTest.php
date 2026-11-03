<?php

use App\Mail\EstimateMail;
use App\Mail\InvoiceMail;
use App\Mail\InvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Estimating\EstimateLifecycle;
use App\Services\Estimating\EstimatePdfRenderer;
use App\Services\Invoicing\InvoicePdfRenderer;
use App\Services\Invoicing\QrBillRenderer;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH', 'email' => 'billing@ernte.test',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
        'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->client = Client::factory()->create(['locale' => 'en', 'address_line_1' => 'Main St 1', 'postal_code' => '8000', 'city' => 'Zürich', 'country' => 'CH']);
    Contact::factory()->for($this->client)->create(['name' => 'Mira Okafor', 'is_default' => true]);
});

test('new documents take the client language unless set explicitly', function () {
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id]);
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id]);
    $german = Invoice::factory()->create(['client_id' => $this->client->id, 'locale' => 'de']);
    $defaultClient = Invoice::factory()->create();

    expect($invoice->locale)->toBe('en')
        ->and($estimate->locale)->toBe('en')
        ->and($german->locale)->toBe('de')
        ->and($defaultClient->fresh()->locale)->toBe('de');
});

test('a document keeps its language when the client changes later', function () {
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id]);
    $this->client->update(['locale' => 'de']);

    expect($invoice->fresh()->locale)->toBe('en');
});

test('english invoice mail: subject, body, plain text and attachment name', function () {
    $invoice = Invoice::factory()->create([
        'client_id' => $this->client->id, 'number' => '2026-014', 'title' => 'Website Relaunch',
        'status' => 'sent', 'due_on' => '2026-12-03', 'total_rappen' => 123450,
    ]);
    Storage::disk('local')->put('invoices/2026-014.pdf', '%PDF-test');

    $mail = new InvoiceMail($invoice, 'invoices/2026-014.pdf');

    expect($mail->build()->subject)->toBe('Invoice 2026-014 - Website Relaunch - Ernte Test');
    $mail->assertSeeInHtml('Hello Mira Okafor,');
    $mail->assertSeeInHtml('Please find attached invoice 2026-014 as a PDF.');
    $mail->assertSeeInHtml('Amount due');
    $mail->assertSeeInHtml('Kind regards');
    $mail->assertSeeInHtml('lang="en"', false);
    $mail->assertDontSeeInHtml('Rechnung');
    $mail->assertSeeInText('Due on: 03.12.2026');
    $mail->assertDontSeeInText('Guten Tag');
    $mail->assertHasAttachment(
        \Illuminate\Mail\Mailables\Attachment::fromPath(Storage::disk('local')->path('invoices/2026-014.pdf'))
            ->as('Ernte-Test-Invoice-2026-014.pdf')
            ->withMime('application/pdf')
    );
});

test('german invoice mail is unchanged', function () {
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'locale' => 'de', 'number' => '2026-015']);

    $mail = new InvoiceMail($invoice, 'invoices/2026-015.pdf');

    expect($mail->build()->subject)->toStartWith('Rechnung 2026-015');
    $mail->assertSeeInHtml('Guten Tag Mira Okafor');
    $mail->assertSeeInHtml('Freundliche Grüsse');
});

test('english estimate and reminder mails', function () {
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-003', 'title' => null]);
    $estimateMail = new EstimateMail($estimate, 'estimates/OF-2026-003.pdf');
    expect($estimateMail->build()->subject)->toBe('Quote OF-2026-003 - Ernte Test');
    $estimateMail->assertSeeInHtml('Please find attached our quote OF-2026-003 as a PDF.');
    $estimateMail->assertSeeInText('We look forward to hearing from you.');

    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-016', 'title' => null, 'status' => 'sent', 'due_on' => '2026-10-01']);
    $reminder = new InvoiceReminderMail($invoice);
    expect($reminder->build()->subject)->toBe('Payment reminder: invoice 2026-016');
    $reminder->assertSeeInHtml('This is a friendly reminder that invoice 2026-016 is still outstanding.');
    $reminder->assertSeeInText('Due since: 01.10.2026');
});

test('english invoice pdf: sheet labels and qr bill', function () {
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-020', 'issued_on' => '2026-11-03', 'due_on' => '2026-12-03']);
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id]);

    $html = app(InvoicePdfRenderer::class)->html($invoice);

    expect($html)->toContain('<html lang="en">')
        ->toContain('Invoice')
        ->toContain('Description')
        ->toContain('Subtotal')
        ->toContain('Payable by <b>03.12.2026</b> (30 days).')
        ->toContain('"Page"')
        ->not->toContain('Rechnung')
        ->not->toContain('Zahlbar')
        ->not->toContain('Zwischensumme');
    // The QR payment part is printed in English too.
    expect(app(QrBillRenderer::class)->html($invoice))->toContain('Payment part')->toContain('Receipt');
    expect($invoice->pdfFilename())->toBe('Ernte-Test-Invoice-2026-020.pdf');
});

test('english estimate pdf', function () {
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-004', 'valid_until' => '2026-12-03']);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id]);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('Quote')
        ->toContain('Total incl. VAT')
        ->toContain('This quote is valid until <b>03.12.2026</b>.')
        ->not->toContain('Offerte')
        ->not->toContain('Inkl. MwSt')
        ->not->toContain('Total exkl. MwSt');
    expect($estimate->pdfFilename())->toBe('Ernte-Test-Quote-OF-2026-004.pdf');
});

test('a converted invoice keeps the estimate language', function () {
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'locale' => 'de', 'status' => 'accepted', 'decided_at' => now()]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id]);

    $invoice = app(EstimateLifecycle::class)->convertToInvoice($estimate);

    expect($invoice->fresh()->locale)->toBe('de');
});

test('recurring period labels follow the locale', function () {
    expect(BillingPeriod::for('monthly', Carbon::parse('2026-06-01'), 1, 'en')['label'])->toBe('June 2026')
        ->and(BillingPeriod::for('quarterly', Carbon::parse('2026-04-01'), 1, 'en')['label'])->toBe('April 2026 – June 2026');
});

test('client and draft document forms save the language', function () {
    $this->actingAs(User::factory()->create());

    $this->patch("/clients/{$this->client->id}", ['locale' => 'de'])->assertRedirect();
    expect($this->client->fresh()->locale)->toBe('de');
    $this->patch("/clients/{$this->client->id}", ['locale' => 'fr'])->assertSessionHasErrors('locale');

    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft']);
    $this->patch("/invoices/{$invoice->id}", ['locale' => 'en'])->assertRedirect();
    expect($invoice->fresh()->locale)->toBe('en');

    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'status' => 'draft']);
    $this->patch("/estimates/{$estimate->id}", ['locale' => 'en'])->assertRedirect();
    expect($estimate->fresh()->locale)->toBe('en');
});
