<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
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
    $this->gen = app(RecurringInvoiceGenerator::class);
    Mail::fake();
});

function schedule(array $overrides = [], array $lineOverrides = []): RecurringInvoice
{
    $client = Client::factory()->create($overrides['client'] ?? []);
    unset($overrides['client']);
    $schedule = RecurringInvoice::factory()->for($client)->create($overrides);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(array_merge([
        'description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000, 'sort_order' => 0,
    ], $lineOverrides));

    return $schedule->fresh('lines');
}

test('generate() creates a draft invoice from template lines with the schedule vat rate', function () {
    $s = schedule(['vat_rate' => 8.10, 'cadence' => 'monthly', 'anchor_day' => 1]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->status)->toBe('draft');
    expect($invoice->recurring_invoice_id)->toBe($s->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->amount_rappen)->toBe(10000); // 1 * 10000, recomputed
    expect($invoice->period_start->toDateString())->toBe('2026-06-01');
    expect($invoice->period_end->toDateString())->toBe('2026-06-30');
    expect((float) $invoice->vat_rate)->toBe(8.10);
    expect($invoice->total_rappen)->toBe(10810);
});

test('generate() interpolates {period} into the title', function () {
    $s = schedule(['title' => 'Hosting — {period}', 'cadence' => 'quarterly', 'anchor_day' => 1]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-04-01'));

    expect($invoice->title)->toBe('Hosting — Q2 2026');
});

test('generate() advances next_run_on and stamps last_generated_on', function () {
    $s = schedule(['cadence' => 'monthly', 'anchor_day' => 15, 'next_run_on' => '2026-06-15']);

    $this->gen->generate($s, Carbon::parse('2026-06-15'));
    $s->refresh();

    expect($s->next_run_on->toDateString())->toBe('2026-07-15');
    expect($s->last_generated_on->toDateString())->toBe('2026-06-15');
});

test('auto_send issues and emails the invoice when the client has an email', function () {
    $s = schedule(['auto_send' => true, 'client' => ['email' => 'client@example.test']]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice->status)->toBe('sent');
    Mail::assertSent(\App\Mail\InvoiceMail::class);
});

test('auto_send leaves a draft and logs recurring_autosend_skipped when the client has no email', function () {
    $s = schedule(['auto_send' => true, 'client' => ['email' => null]]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice->status)->toBe('draft');
    expect($invoice->events()->where('kind', 'recurring_autosend_skipped')->count())->toBe(1);
    Mail::assertNothingSent();
});
