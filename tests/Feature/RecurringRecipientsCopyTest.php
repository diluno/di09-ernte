<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    Mail::fake();
});

it('copies recurring recipients onto generated invoices', function () {
    $client = Client::factory()->create();
    $schedule = RecurringInvoice::factory()->for($client)->create([
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
        'cadence' => 'monthly',
        'anchor_day' => 1,
        'next_run_on' => '2026-06-01',
    ]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create([
        'description' => 'Retainer', 'hours' => 1, 'rate_rappen' => 10000, 'sort_order' => 0,
    ]);

    $invoice = app(RecurringInvoiceGenerator::class)->generate($schedule, Carbon::parse('2026-06-01'));

    expect($invoice->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});
