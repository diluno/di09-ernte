<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    Mail::fake();
    Carbon::setTestNow('2026-06-20');
});

afterEach(fn () => Carbon::setTestNow());

function makeSchedule(array $overrides = []): RecurringInvoice
{
    $schedule = RecurringInvoice::factory()->create($overrides);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create([
        'description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000,
    ]);

    return $schedule->fresh('lines');
}

test('generates invoices for due schedules and advances them', function () {
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-06-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(1);
    expect(RecurringInvoice::first()->next_run_on->toDateString())->toBe('2026-07-01');
});

test('skips paused schedules', function () {
    makeSchedule(['next_run_on' => '2026-06-01'])->update(['paused_at' => now()]);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

test('does not touch schedules whose next run is in the future', function () {
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-07-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

test('catches up multiple missed monthly periods in one run', function () {
    // next_run three months back; today is 2026-06-20 → generate Mar, Apr, May, Jun = 4.
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-03-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(4);
    expect(RecurringInvoice::first()->next_run_on->toDateString())->toBe('2026-07-01');
});
