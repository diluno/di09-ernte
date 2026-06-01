<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class BillingPeriod
{
    /**
     * The anchor-aligned period an invoice covers: from its run date forward one
     * full cadence, i.e. [run date, day before the next run]. This is advance
     * billing — billing on the anchor for the upcoming cadence window.
     *
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public static function for(string $cadence, Carbon $runDate, int $anchorDay): array
    {
        $start = $runDate->copy()->startOfDay();
        $end = self::advance($cadence, $start, $anchorDay)->subDay();

        return [
            'start' => $start,
            'end' => $end,
            'label' => self::label($start, $end),
        ];
    }

    /** Advance $date by one $cadence step (1/3/6/12 months), preserving $anchorDay clamped to the target month's length. */
    public static function advance(string $cadence, Carbon $date, int $anchorDay): Carbon
    {
        $months = self::months($cadence);
        $target = $date->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $target->setDay(min($anchorDay, $target->daysInMonth))->startOfDay();
    }

    /** The first occurrence on or after $from, stepping from $start by the cadence (no history backfill). */
    public static function nextRunOnOrAfter(string $cadence, Carbon $start, Carbon $from): Carbon
    {
        $anchorDay = $start->day;
        $next = $start->copy()->startOfDay();
        $floor = $from->copy()->startOfDay();
        $guard = 0;

        while ($next->lt($floor) && $guard++ < 1200) { // 1200 monthly steps ≈ 100 years
            $next = self::advance($cadence, $next, $anchorDay);
        }

        if ($next->lt($floor)) {
            throw new \RuntimeException('BillingPeriod::nextRunOnOrAfter exceeded its iteration guard.');
        }

        return $next;
    }

    private static function months(string $cadence): int
    {
        return match ($cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'half-yearly' => 6,
            'yearly' => 12,
            default => throw new \InvalidArgumentException("Unknown cadence: {$cadence}"),
        };
    }

    /**
     * A German month-year label for the period: a single "MMMM YYYY" when the span
     * stays within one calendar month, otherwise "MMMM YYYY – MMMM YYYY".
     */
    private static function label(Carbon $start, Carbon $end): string
    {
        $startLabel = $start->copy()->locale('de')->translatedFormat('F Y');

        if ($start->isSameMonth($end)) {
            return $startLabel;
        }

        return $startLabel . ' – ' . $end->copy()->locale('de')->translatedFormat('F Y');
    }
}
