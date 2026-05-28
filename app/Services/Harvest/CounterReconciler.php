<?php

namespace App\Services\Harvest;

use App\Models\Estimate;
use App\Models\EstimateCounter;
use App\Models\Invoice;
use App\Models\InvoiceCounter;

class CounterReconciler
{
    public static function reconcileInvoices(): void
    {
        self::reconcile(Invoice::pluck('number'), '/^(\d{4})-(\d+)$/', InvoiceCounter::class);
    }

    public static function reconcileEstimates(): void
    {
        self::reconcile(Estimate::pluck('number'), '/^OF-(\d{4})-(\d+)$/', EstimateCounter::class);
    }

    /**
     * @param  iterable<string>  $numbers
     * @param  class-string<InvoiceCounter|EstimateCounter>  $counterModel
     */
    private static function reconcile(iterable $numbers, string $pattern, string $counterModel): void
    {
        $maxByYear = [];
        foreach ($numbers as $number) {
            if (preg_match($pattern, (string) $number, $m)) {
                $year = (int) $m[1];
                $n = (int) $m[2];
                $maxByYear[$year] = max($maxByYear[$year] ?? 0, $n);
            }
        }

        foreach ($maxByYear as $year => $maxN) {
            $counter = $counterModel::firstOrNew(['year' => $year]);
            $counter->last_n = max((int) ($counter->last_n ?? 0), $maxN);
            $counter->save();
        }
    }
}
