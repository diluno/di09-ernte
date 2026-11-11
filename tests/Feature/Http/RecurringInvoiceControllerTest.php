<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
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
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000]],
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
        'lines' => [['description' => 'New line', 'hours' => 2, 'rate_rappen' => 5000]],
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

test('resume preserves a month-end anchor after a clamped February date', function () {
    Carbon::setTestNow('2026-03-01');
    $schedule = RecurringInvoice::factory()->create([
        'cadence' => 'monthly',
        'anchor_day' => 31,
        'next_run_on' => '2026-02-28',
        'paused_at' => now()->subDay(),
    ]);

    $this->post("/recurring-invoices/{$schedule->id}/resume")->assertRedirect();

    expect($schedule->fresh()->next_run_on->toDateString())->toBe('2026-03-31')
        ->and($schedule->fresh()->anchor_day)->toBe(31);
});

test('editing without changing a clamped run date preserves the original anchor', function () {
    Carbon::setTestNow('2026-02-01');
    $schedule = RecurringInvoice::factory()->create([
        'cadence' => 'monthly',
        'anchor_day' => 31,
        'next_run_on' => '2026-02-28',
    ]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create([
        'description' => 'Hosting',
        'hours' => 1,
        'rate_rappen' => 14550,
    ]);

    $this->patch("/recurring-invoices/{$schedule->id}", [
        'client_id' => $schedule->client_id,
        'cadence' => 'monthly',
        'next_run_on' => '2026-02-28',
        'vat_rate' => 8.10,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 14550]],
    ])->assertRedirect('/recurring-invoices');

    expect($schedule->fresh()->anchor_day)->toBe(31)
        ->and($schedule->fresh()->next_run_on->toDateString())->toBe('2026-02-28');
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
    $project = Project::factory()->create(['rate_rappen' => 14550]);
    $schedule = RecurringInvoice::factory()->create(['client_id' => $project->client_id, 'project_id' => $project->id]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(['rate_rappen' => 14550]);

    $this->get("/recurring-invoices/{$schedule->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('RecurringInvoices/Edit')
            ->where('schedule.lines.0.rate', 145.5)
            ->where('projects.0.rate', 145.5)
            ->etc());
});

test('index resolves {period} for the next occurrence and computes the stats strip in rappen', function () {
    $client = Client::factory()->create(['name' => 'Acme AG', 'locale' => 'en']);
    $monthly = RecurringInvoice::factory()->create([
        'client_id' => $client->id, 'title' => 'Hosting — {period}',
        'cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-06-01',
    ]);
    RecurringInvoiceLine::factory()->for($monthly, 'recurringInvoice')->create(['hours' => 1, 'rate_rappen' => 10000]);
    RecurringInvoiceLine::factory()->for($monthly, 'recurringInvoice')->create(['hours' => 2.5, 'rate_rappen' => 12000, 'sort_order' => 1]);

    $quarterly = RecurringInvoice::factory()->autoSend()->create([
        'title' => null, 'cadence' => 'quarterly', 'anchor_day' => 1, 'next_run_on' => '2026-07-01',
    ]);
    RecurringInvoiceLine::factory()->for($quarterly, 'recurringInvoice')->create(['hours' => 1, 'rate_rappen' => 50000]);

    $paused = RecurringInvoice::factory()->paused()->create(['cadence' => 'yearly', 'next_run_on' => '2026-06-15']);
    RecurringInvoiceLine::factory()->for($paused, 'recurringInvoice')->create(['hours' => 1, 'rate_rappen' => 100000]);

    $this->get('/recurring-invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('RecurringInvoices/Index')
            ->has('schedules', 3)
            ->where('schedules.0.id', $monthly->id)
            ->where('schedules.0.title', 'Hosting — {period}')
            ->where('schedules.0.resolved_title', 'Hosting — June 2026')
            ->where('schedules.0.subtotal_per_run', 40000)
            ->where('schedules.0.run_late', false)
            ->where('schedules.1.resolved_title', null)
            ->where('schedules.2.paused', true)
            ->where('stats.active', 2)
            ->where('stats.active_auto_send', 1)
            ->where('stats.paused', 1)
            ->where('stats.next_run.on', '2026-06-01')
            ->where('stats.next_run.client', 'Acme AG')
            ->where('stats.next_run.late', false)
            // 12 × 400.00 + 4 × 500.00; the paused schedule does not count.
            ->where('stats.yearly_rappen', 680000));
});

test('index flags an active schedule whose run date has passed', function () {
    $late = RecurringInvoice::factory()->create(['next_run_on' => '2026-05-20']);
    RecurringInvoiceLine::factory()->for($late, 'recurringInvoice')->create();
    RecurringInvoice::factory()->paused()->create(['next_run_on' => '2026-05-01']);

    $this->get('/recurring-invoices')
        ->assertInertia(fn (Assert $p) => $p
            ->where('schedules.0.run_late', true)
            ->where('schedules.1.run_late', false)
            ->where('stats.next_run.late', true));
});

test('edit exposes the resolved title of the next invoice', function () {
    $client = Client::factory()->create(['locale' => 'en']);
    $schedule = RecurringInvoice::factory()->create([
        'client_id' => $client->id, 'title' => 'Retainer {period}',
        'cadence' => 'quarterly', 'anchor_day' => 1, 'next_run_on' => '2026-07-01',
    ]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->get("/recurring-invoices/{$schedule->id}/edit")
        ->assertInertia(fn (Assert $p) => $p
            ->where('schedule.next_title', 'Retainer July 2026 – September 2026')
            ->etc());
});
