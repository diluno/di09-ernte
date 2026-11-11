<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class EntryDuration
{
    /**
     * SQL expression for a time entry's duration in seconds.
     *
     * started_at/ended_at store app-timezone wall-clock, so a running entry has to
     * be measured against PHP's now() in that same timezone. The database clock
     * (UTC_TIMESTAMP()/NOW()) follows the server's timezone and would skew every
     * running timer by the UTC offset. The clamp keeps a clock hiccup from
     * producing negative time.
     *
     * @param  string  $table  Qualifier for joined queries, e.g. "time_entries".
     */
    public static function secondsSql(string $table = ''): string
    {
        $column = $table !== '' ? "{$table}." : '';
        // Fixed-format digits only, so it is safe to inline and needs no binding.
        $now = Carbon::now()->format('Y-m-d H:i:s');

        return "GREATEST(TIMESTAMPDIFF(SECOND, {$column}started_at, COALESCE({$column}ended_at, '{$now}')), 0)";
    }
}
