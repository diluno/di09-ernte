<?php

namespace App\Services\Estimating;

use Illuminate\Support\Facades\DB;

class EstimateNumberer
{
    /**
     * Allocate the next estimate number for the given year, formatted "OF-YYYY-NNN".
     *
     * Uses MariaDB's INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(expr) trick:
     * the UPDATE clause both stores the new value AND publishes it via LAST_INSERT_ID(),
     * so we can read it back atomically with a follow-up SELECT — no race window.
     */
    public function nextFor(int $year): string
    {
        $n = DB::transaction(function () use ($year) {
            DB::statement(
                "INSERT INTO estimate_counters (year, last_n, created_at, updated_at) " .
                "VALUES (?, LAST_INSERT_ID(1), NOW(), NOW()) " .
                "ON DUPLICATE KEY UPDATE last_n = LAST_INSERT_ID(last_n + 1), updated_at = NOW()",
                [$year]
            );
            return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
        });

        return sprintf('OF-%d-%03d', $year, $n);
    }
}
