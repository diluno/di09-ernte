<?php

use App\Mail\EstimateMail;
use App\Mail\InvoiceMail;
use App\Mail\InvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

// The greeting must name the person the mail goes to (the document's first
// recipient), not the client's default contact.
beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH', 'email' => 'billing@ernte.test',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
    ]);
    $this->client = Client::factory()->create();
    Contact::factory()->for($this->client)->create(['name' => 'Dora Default', 'email' => 'dora@example.test', 'is_default' => true]);
    $this->recipients = [
        ['name' => 'Rita Recipient', 'email' => 'rita@example.test'],
        ['name' => 'Carl Copied', 'email' => 'carl@example.test'],
    ];
    Storage::fake('local');
    Storage::disk('local')->put('doc.pdf', '%PDF-test');
});

test('invoice and reminder mails greet the invoice\'s first recipient', function () {
    $invoice = Invoice::factory()->sent()->create(['client_id' => $this->client->id, 'recipients' => $this->recipients]);

    foreach ([new InvoiceMail($invoice, 'doc.pdf'), new InvoiceReminderMail($invoice)] as $mail) {
        $mail->assertSeeInHtml('Rita Recipient');
        $mail->assertSeeInText('Rita Recipient');
        $mail->assertDontSeeInHtml('Dora Default');
        $mail->assertDontSeeInText('Dora Default');
        $mail->assertDontSeeInHtml('Carl Copied');
    }
});

test('estimate mail greets the estimate\'s first recipient', function () {
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'recipients' => $this->recipients]);

    $mail = new EstimateMail($estimate, 'doc.pdf');

    $mail->assertSeeInHtml('Rita Recipient');
    $mail->assertSeeInText('Rita Recipient');
    $mail->assertDontSeeInHtml('Dora Default');
    $mail->assertDontSeeInText('Dora Default');
});

test('documents without stored recipients still greet the client\'s default contact', function () {
    $invoice = Invoice::factory()->sent()->create(['client_id' => $this->client->id, 'recipients' => null]);

    expect($invoice->effectiveRecipients())->toBe([['name' => 'Dora Default', 'email' => 'dora@example.test']]);
    (new InvoiceMail($invoice, 'doc.pdf'))->assertSeeInHtml('Dora Default');
});
