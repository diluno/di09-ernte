<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class BillingPeriod
{
    /**
     * The calendar period containing $date (advance billing).
     *
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public static function for(string $cadence, Carbon $date): array
    {
        $d = $date->copy()->startOfDay();

        return match ($cadence) {
            'monthly' => [
                'start' => $d->copy()->startOfMonth(),
                'end' => $d->copy()->endOfMonth()->startOfDay(),
                'label' => $d->format('F Y'),
            ],
            'quarterly' => [
                'start' => $d->copy()->startOfQuarter(),
                'end' => $d->copy()->endOfQuarter()->startOfDay(),
                'label' => 'Q' . $d->quarter . ' ' . $d->year,
            ],
            'half-yearly' => self::half($d),
            'yearly' => [
                'start' => $d->copy()->startOfYear(),
                'end' => $d->copy()->endOfYear()->startOfDay(),
                'label' => (string) $d->year,
            ],
            default => throw new \InvalidArgumentException("Unknown cadence: {$cadence}"),
        };
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

    private static function half(Carbon $d): array
    {
        $firstHalf = $d->month <= 6;

        return [
            'start' => $firstHalf ? $d->copy()->startOfYear() : $d->copy()->setDate($d->year, 7, 1)->startOfDay(),
            'end' => $firstHalf ? $d->copy()->setDate($d->year, 6, 30)->startOfDay() : $d->copy()->endOfYear()->startOfDay(),
            'label' => ($firstHalf ? 'H1 ' : 'H2 ') . $d->year,
        ];
    }
}
