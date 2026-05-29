<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->actingAs(User::factory()->create());
    Mail::fake();
    Carbon::setTestNow('2026-05-29');
});

afterEach(fn () => Carbon::setTestNow());

test('index renders the schedules page', function () {
    $this->get('/recurring-invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('RecurringInvoices/Index')->has('schedules'));
});

test('store creates a schedule with lines and snaps a past first-run forward', function () {
    $client = Client::factory()->create();

    $this->post('/recurring-invoices', [
        'client_id' => $client->id,
        'title' => 'Hosting — {period}',
        'cadence' => 'monthly',
        'next_run_on' => '2026-01-10',     // in the past relative to 2026-05-29
        'vat_rate' => 8.10,
        'auto_send' => false,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    ])->assertRedirect('/recurring-invoices');

    $schedule = RecurringInvoice::first();
    expect($schedule)->not->toBeNull();
    expect($schedule->anchor_day)->toBe(10);
    expect($schedule->next_run_on->toDateString())->toBe('2026-06-10'); // snapped forward, no backfill
    expect($schedule->lines)->toHaveCount(1);
});

test('store rejects a schedule with no lines', function () {
    $client = Client::factory()->create();

    $this->post('/recurring-invoices', [
        'client_id' => $client->id, 'cadence' => 'monthly', 'next_run_on' => '2026-06-01', 'vat_rate' => 8.10, 'lines' => [],
    ])->assertSessionHasErrors('lines');
});

test('update replaces lines and reschedules', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly']);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->patch("/recurring-invoices/{$schedule->id}", [
        'client_id' => $schedule->client_id,
        'cadence' => 'quarterly',
        'next_run_on' => '2026-07-01',
        'vat_rate' => 8.10,
        'lines' => [['description' => 'New line', 'hours' => 2, 'rate_rappen' => 5000, 'vat_exempt' => true]],
    ])->assertRedirect('/recurring-invoices');

    $schedule->refresh()->load('lines');
    expect($schedule->cadence)->toBe('quarterly');
    expect($schedule->lines)->toHaveCount(1);
    expect($schedule->lines->first()->description)->toBe('New line');
});

test('pause and resume toggle the schedule and snap next run forward', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-01-01']);

    $this->post("/recurring-invoices/{$schedule->id}/pause")->assertRedirect();
    expect($schedule->fresh()->isPaused())->toBeTrue();

    $this->post("/recurring-invoices/{$schedule->id}/resume")->assertRedirect();
    $schedule->refresh();
    expect($schedule->isPaused())->toBeFalse();
    expect($schedule->next_run_on->toDateString())->toBe('2026-06-01'); // snapped forward
});

test('run generates an invoice immediately and redirects to it', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-06-01']);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(['hours' => 1, 'rate_rappen' => 10000]);

    $this->post("/recurring-invoices/{$schedule->id}/run")->assertRedirect();

    expect(Invoice::where('recurring_invoice_id', $schedule->id)->count())->toBe(1);
});

test('destroy deletes the schedule but keeps generated invoices', function () {
    $schedule = RecurringInvoice::factory()->create();
    $invoice = Invoice::factory()->create(['recurring_invoice_id' => $schedule->id]);

    $this->delete("/recurring-invoices/{$schedule->id}")->assertRedirect('/recurring-invoices');

    expect(RecurringInvoice::find($schedule->id))->toBeNull();
    expect($invoice->fresh())->not->toBeNull();
    expect($invoice->fresh()->recurring_invoice_id)->toBeNull();
});

test('edit renders the edit page', function () {
    $schedule = RecurringInvoice::factory()->create();
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->get("/recurring-invoices/{$schedule->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('RecurringInvoices/Edit')->has('schedule'));
});
