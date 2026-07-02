<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;

it('defaults invoice recipients from the client default contacts', function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Nope', 'email' => 'no@x.ch', 'is_default' => false]);

    $invoice = app(InvoiceBuilder::class)->createDraft(
        client: $client, project: null,
        periodStart: '2026-07-01', periodEnd: '2026-07-31',
        lines: [['description' => 'Work', 'hours' => 1, 'rate_rappen' => 10000]],
        entryIds: [],
    );

    expect($invoice->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('defaults estimate recipients from the client default contacts on creation via POST', function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $project = Project::factory()->create(['client_id' => $client->id, 'billable' => true, 'rate_rappen' => 14500]);
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Nope', 'email' => 'no@x.ch', 'is_default' => false]);

    $this->post('/estimates', [
        'client_id' => $client->id,
        'project_id' => $project->id,
        'title' => 'Test Estimate',
        'notes' => 'Notes',
        'lines' => [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500],
        ],
    ]);

    $estimate = Estimate::latest('id')->first();
    expect($estimate->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('defaults recurring invoice recipients from the client default contacts on creation via POST', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Nope', 'email' => 'no@x.ch', 'is_default' => false]);

    $this->post('/recurring-invoices', [
        'client_id' => $client->id,
        'title' => 'Hosting — {period}',
        'cadence' => 'monthly',
        'next_run_on' => '2026-01-10',
        'vat_rate' => 8.10,
        'auto_send' => false,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000]],
    ]);

    $schedule = RecurringInvoice::first();
    expect($schedule->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('honors an explicitly submitted recipients set on invoice creation via POST', function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);

    $chosen = [['name' => 'Chosen Recipient', 'email' => 'chosen@x.ch']];

    $this->post('/invoices', [
        'client_id' => $client->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'lines' => [['description' => 'Work', 'hours' => 1, 'rate_rappen' => 10000]],
        'recipients' => $chosen,
    ]);

    $invoice = Invoice::latest('id')->first();
    expect($invoice->recipients)->toBe($chosen);
});

it('honors an explicitly submitted recipients set on estimate creation via POST', function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $project = Project::factory()->create(['client_id' => $client->id, 'billable' => true, 'rate_rappen' => 14500]);
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);

    $chosen = [['name' => 'Chosen Recipient', 'email' => 'chosen@x.ch']];

    $this->post('/estimates', [
        'client_id' => $client->id,
        'project_id' => $project->id,
        'title' => 'Test Estimate',
        'notes' => 'Notes',
        'lines' => [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500],
        ],
        'recipients' => $chosen,
    ]);

    $estimate = Estimate::latest('id')->first();
    expect($estimate->recipients)->toBe($chosen);
});

it('honors an explicitly submitted recipients set on recurring invoice creation via POST', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);

    $chosen = [['name' => 'Chosen Recipient', 'email' => 'chosen@x.ch']];

    $this->post('/recurring-invoices', [
        'client_id' => $client->id,
        'title' => 'Hosting — {period}',
        'cadence' => 'monthly',
        'next_run_on' => '2026-01-10',
        'vat_rate' => 8.10,
        'auto_send' => false,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000]],
        'recipients' => $chosen,
    ]);

    $schedule = RecurringInvoice::first();
    expect($schedule->recipients)->toBe($chosen);
});
