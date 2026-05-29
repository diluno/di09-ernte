<?php

use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;

test('for() returns calendar period and label per cadence', function () {
    $d = Carbon::parse('2026-06-15');

    $m = BillingPeriod::for('monthly', $d);
    expect($m['start']->toDateString())->toBe('2026-06-01');
    expect($m['end']->toDateString())->toBe('2026-06-30');
    expect($m['label'])->toBe('June 2026');

    $q = BillingPeriod::for('quarterly', $d);
    expect($q['start']->toDateString())->toBe('2026-04-01');
    expect($q['end']->toDateString())->toBe('2026-06-30');
    expect($q['label'])->toBe('Q2 2026');

    $h = BillingPeriod::for('half-yearly', $d);
    expect($h['start']->toDateString())->toBe('2026-01-01');
    expect($h['end']->toDateString())->toBe('2026-06-30');
    expect($h['label'])->toBe('H1 2026');

    $h2 = BillingPeriod::for('half-yearly', Carbon::parse('2026-09-10'));
    expect($h2['start']->toDateString())->toBe('2026-07-01');
    expect($h2['end']->toDateString())->toBe('2026-12-31');
    expect($h2['label'])->toBe('H2 2026');

    $y = BillingPeriod::for('yearly', $d);
    expect($y['start']->toDateString())->toBe('2026-01-01');
    expect($y['end']->toDateString())->toBe('2026-12-31');
    expect($y['label'])->toBe('2026');
});

test('advance() steps by cadence and clamps the anchor day to short months', function () {
    // Monthly from Jan 31 → Feb 28 (clamped) → Mar 31 (springs back).
    $feb = BillingPeriod::advance('monthly', Carbon::parse('2026-01-31'), 31);
    expect($feb->toDateString())->toBe('2026-02-28');
    $mar = BillingPeriod::advance('monthly', $feb, 31);
    expect($mar->toDateString())->toBe('2026-03-31');

    expect(BillingPeriod::advance('quarterly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2026-05-15');
    expect(BillingPeriod::advance('half-yearly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2026-08-15');
    expect(BillingPeriod::advance('yearly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2027-02-15');
});

test('nextRunOnOrAfter() snaps a past start forward without backfilling', function () {
    $start = Carbon::parse('2026-01-10');
    $from = Carbon::parse('2026-05-29');
    expect(BillingPeriod::nextRunOnOrAfter('monthly', $start, $from)->toDateString())->toBe('2026-06-10');

    // A future start is returned unchanged.
    expect(BillingPeriod::nextRunOnOrAfter('monthly', Carbon::parse('2026-07-01'), $from)->toDateString())->toBe('2026-07-01');
});

test('nextRunOnOrAfter() steps non-monthly cadences correctly', function () {
    $next = \App\Support\BillingPeriod::nextRunOnOrAfter('quarterly', \Illuminate\Support\Carbon::parse('2026-01-15'), \Illuminate\Support\Carbon::parse('2026-08-01'));
    expect($next->toDateString())->toBe('2026-10-15');
});
