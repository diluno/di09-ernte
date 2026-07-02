<?php

use App\Mail\InvoiceMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Facades\Mail;

it('sends an invoice to all default contacts, first To then Cc', function () {
    Mail::fake();
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
    ]);
    $client = Client::factory()->create(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Acct', 'email' => 'acct@x.ch', 'is_default' => true, 'sort_order' => 1]);
    $invoice = Invoice::factory()->for($client)->has(\App\Models\InvoiceLine::factory()->count(1), 'lines')->create(['status' => 'draft']);

    app(InvoiceLifecycle::class)->issue($invoice);

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('marc@x.ch') && $mail->hasCc('acct@x.ch');
    });
})->group('browsershot');

it('refuses to send when the client has no default contacts', function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $client = Client::factory()->create(); // no contacts
    $invoice = Invoice::factory()->for($client)->has(\App\Models\InvoiceLine::factory()->count(1), 'lines')->create(['status' => 'draft']);

    expect(fn () => app(InvoiceLifecycle::class)->issue($invoice))
        ->toThrow(\DomainException::class);
});
