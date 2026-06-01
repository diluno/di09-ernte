<?php

use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;

test('for() returns the anchor-aligned forward period per cadence', function () {
    // Period runs from the run date (anchor) to the day before the next run.
    $m = BillingPeriod::for('monthly', Carbon::parse('2026-06-01'), 1);
    expect($m['start']->toDateString())->toBe('2026-06-01');
    expect($m['end']->toDateString())->toBe('2026-06-30');

    $q = BillingPeriod::for('quarterly', Carbon::parse('2026-04-01'), 1);
    expect($q['start']->toDateString())->toBe('2026-04-01');
    expect($q['end']->toDateString())->toBe('2026-06-30');

    $h = BillingPeriod::for('half-yearly', Carbon::parse('2026-06-01'), 1);
    expect($h['start']->toDateString())->toBe('2026-06-01');
    expect($h['end']->toDateString())->toBe('2026-11-30');

    $y = BillingPeriod::for('yearly', Carbon::parse('2026-06-01'), 1);
    expect($y['start']->toDateString())->toBe('2026-06-01');
    expect($y['end']->toDateString())->toBe('2027-05-31');
});

test('for() labels a single-month period with one month, a multi-month span as a German range', function () {
    // Monthly anchored on the 1st spans a single calendar month → one label.
    expect(BillingPeriod::for('monthly', Carbon::parse('2026-06-01'), 1)['label'])->toBe('Juni 2026');

    // Monthly anchored mid-month spans two months → range.
    expect(BillingPeriod::for('monthly', Carbon::parse('2026-06-15'), 15)['label'])->toBe('Juni 2026 – Juli 2026');

    // Quarterly / yearly always span multiple months → range, crossing the year for yearly.
    expect(BillingPeriod::for('quarterly', Carbon::parse('2026-04-01'), 1)['label'])->toBe('April 2026 – Juni 2026');
    expect(BillingPeriod::for('yearly', Carbon::parse('2026-06-01'), 1)['label'])->toBe('Juni 2026 – Mai 2027');
});

test('for() clamps the period end via the anchor day on short months', function () {
    // Anchor 31, run on Jan 31: next run clamps to Feb 28, so the period ends Feb 27.
    $p = BillingPeriod::for('monthly', Carbon::parse('2026-01-31'), 31);
    expect($p['start']->toDateString())->toBe('2026-01-31');
    expect($p['end']->toDateString())->toBe('2026-02-27');
    expect($p['label'])->toBe('Januar 2026 – Februar 2026');
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
