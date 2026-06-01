<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardProjections
{
    private const DURATION_SECONDS_SQL = 'GREATEST(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, ?)), 0)';

    /**
     * Project list as shown on /projects, with computed `spent_*`, `band`, `last_activity_at`,
     * `sparkline` (14 numbers, hours per day for the past 14 days).
     *
     * @return Collection<int, array>
     */
    public static function projects(string $filter = 'active', ?string $search = null): Collection
    {
        $q = Project::query()->with('client:id,name');

        if ($filter === 'active') {
            $q->where('status', 'active');
        }
        if ($filter === 'archived') {
            $q->where('status', 'archived');
        }

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('projects.name', 'like', "%{$search}%")
                    ->orWhere('projects.code', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $projects = $q->orderByDesc('updated_at')->get();
        $ids = $projects->pluck('id');
        $now = Carbon::now()->toDateTimeString();

        // One aggregate query for all projects: total seconds + last_activity_at.
        $totals = TimeEntry::query()
            ->whereIn('project_id', $ids)
            ->selectRaw('
                project_id,
                COALESCE(SUM('.self::DURATION_SECONDS_SQL.'), 0) AS secs,
                MAX(started_at) AS last_started_at
            ', [$now])
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        // 14-day sparklines: one query, group by (project, day).
        $start = Carbon::today()->subDays(13);
        $spark = TimeEntry::query()
            ->whereIn('project_id', $ids)
            ->where('started_at', '>=', $start)
            ->selectRaw('
                project_id,
                DATE(started_at) AS day,
                SUM('.self::DURATION_SECONDS_SQL.') AS secs
            ', [$now])
            ->groupBy('project_id', 'day')
            ->get()
            ->groupBy('project_id');

        return $projects->map(function (Project $p) use ($totals, $spark, $start) {
            $t = $totals->get($p->id);
            $secs = $t ? (int) $t->secs : 0;
            $hours = round($secs / 3600, 2);
            $amount = (int) round($hours * (int) $p->rate_rappen);
            $pct = $p->budget_hours > 0 ? (int) round(($hours / $p->budget_hours) * 100) : 0;
            $band = $pct > 100 ? 'over' : ($pct >= 85 ? 'warn' : 'ok');

            $byDay = ($spark->get($p->id) ?? collect())->keyBy('day');
            $sparkline = [];
            for ($i = 0; $i < 14; $i++) {
                $key = $start->copy()->addDays($i)->toDateString();
                $sparkline[] = round((($byDay->get($key)->secs ?? 0)) / 3600, 1);
            }

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status,
                'billable' => (bool) $p->billable,
                'retainer' => (bool) $p->retainer,
                'rate' => (int) round($p->rate_rappen / 100),
                'budget_hours' => (int) $p->budget_hours,
                'budget_amount' => (int) round($p->budget_amount_rappen / 100),
                'spent_hours' => $hours,
                'spent_amount' => round($amount / 100, 2),
                'pct_hours' => $pct,
                'band' => $band,
                'last_activity_at' => $t?->last_started_at,
                'client' => [
                    'id' => $p->client->id,
                    'name' => $p->client->name,
                ],
                'sparkline' => $sparkline,
            ];
        });
    }

    /** Single aggregate payload for the iOS Home tab. Composes existing projections. */
    public static function summary(User $user): array
    {
        $timer = TimerToday::payload($user);
        $stats = self::stats($user);

        return [
            'timer' => [
                'running' => self::runningEntry($user),
                'today' => $timer['totals'],
            ],
            'hours' => [
                'week_hours' => $stats['week_hours'],
                'sparkline' => self::dailyHoursSparkline($user, 7),
            ],
            'money' => [
                'outstanding' => $stats['outstanding_amount'],
                'overdue' => $stats['overdue_amount'],
                'unbilled' => $stats['unbilled_amount'],
            ],
        ];
    }

    /** The user's currently running entry in the API's running-entry shape, or null. */
    private static function runningEntry(User $user): ?array
    {
        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with(['project:id,name,code', 'task:id,name'])
            ->first();

        return $running ? [
            'id' => $running->id,
            'description' => $running->description,
            'task_name' => $running->task?->name,
            'started_at' => $running->started_at->toIso8601String(),
            'duration_seconds' => $running->duration_seconds,
            'billable' => (bool) $running->billable,
            'project' => [
                'id' => $running->project->id,
                'name' => $running->project->name,
                'code' => $running->project->code,
            ],
        ] : null;
    }

    /** Hours tracked per day for the past $days days (oldest→newest), for the sparkline. */
    private static function dailyHoursSparkline(User $user, int $days): array
    {
        $start = Carbon::today()->subDays($days - 1);
        $now = Carbon::now()->toDateTimeString();

        $byDay = TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $start)
            ->selectRaw('DATE(started_at) AS day, SUM('.self::DURATION_SECONDS_SQL.') AS secs', [$now])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $key = $start->copy()->addDays($i)->toDateString();
            $out[] = round(($byDay->get($key)->secs ?? 0) / 3600, 1);
        }

        return $out;
    }

    /** Top-of-page summary numbers. */
    public static function stats(User $user): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $now = Carbon::now()->toDateTimeString();

        $weekSecs = (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $weekStart)
            ->selectRaw('COALESCE(SUM('.self::DURATION_SECONDS_SQL.'), 0) AS s', [$now])
            ->value('s');

        // Unbilled = billable entries with NULL invoice_id, summed across all projects.
        $unbilledRows = TimeEntry::query()
            ->where('time_entries.user_id', $user->id)
            ->whereNull('time_entries.invoice_id')
            ->where('time_entries.billable', true)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                COALESCE(SUM('.self::DURATION_SECONDS_SQL.' * projects.rate_rappen) / 3600, 0) AS rappen,
                COALESCE(SUM('.self::DURATION_SECONDS_SQL.'), 0) AS secs
            ', [$now, $now])
            ->first();

        $invoice = InvoiceProjections::stats();

        return [
            'active' => Project::active()->count(),
            'week_hours' => round($weekSecs / 3600, 1),
            'unbilled_amount' => round(((float) $unbilledRows->rappen) / 100, 2),
            'unbilled_hours' => round(((int) $unbilledRows->secs) / 3600, 1),
            'outstanding_amount' => $invoice['outstanding'],
            'overdue_amount' => $invoice['overdue'],
            'outstanding_count' => Invoice::outstanding()->count(),
        ];
    }
}
