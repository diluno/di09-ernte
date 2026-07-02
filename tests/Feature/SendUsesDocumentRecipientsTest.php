<?php

use App\Mail\InvoiceMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Facades\Mail;

it('sends to the invoice recipient snapshot, not the client defaults', function () {
    Mail::fake();
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
    ]);
    $client = Client::factory()->create(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    // Client default differs from the invoice snapshot to prove the snapshot wins.
    Contact::factory()->for($client)->create(['name' => 'Default', 'email' => 'default@x.ch', 'is_default' => true]);
    $invoice = Invoice::factory()->for($client)->has(InvoiceLine::factory()->count(1), 'lines')->create([
        'status' => 'draft',
        'recipients' => [['name' => 'Chosen', 'email' => 'chosen@x.ch']],
    ]);

    app(InvoiceLifecycle::class)->issue($invoice);

    Mail::assertSent(InvoiceMail::class, fn ($m) => $m->hasTo('chosen@x.ch') && ! $m->hasTo('default@x.ch'));
})->group('browsershot');
