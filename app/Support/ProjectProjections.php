<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

class ProjectProjections
{
    /**
     * Stats strip on /projects. Money is integer rappen.
     *
     * - month_hours: the user's tracked hours since the 1st of this month
     * - unbilled_rappen / unbilled_hours: billable, not yet invoiced time (all projects)
     * - over_budget_count: active projects whose hours exceed their budget
     *   (same rounding as the per-project `band === 'over'`)
     */
    public static function indexStats(User $user): array
    {
        $monthSecs = (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', Carbon::now()->startOfMonth())
            ->selectRaw('COALESCE(SUM('.EntryDuration::secondsSql().'), 0) AS s')
            ->value('s');

        $unbilled = TimeEntry::query()
            ->where('time_entries.user_id', $user->id)
            ->whereNull('time_entries.invoice_id')
            ->where('time_entries.billable', true)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                COALESCE(SUM('.EntryDuration::secondsSql('time_entries').' * projects.rate_rappen) / 3600, 0) AS rappen,
                COALESCE(SUM('.EntryDuration::secondsSql('time_entries').'), 0) AS secs
            ')
            ->first();

        $budgeted = Project::active()->where('budget_hours', '>', 0)->get(['id', 'budget_hours']);
        $secsByProject = TimeEntry::query()
            ->whereIn('project_id', $budgeted->pluck('id'))
            ->selectRaw('project_id, COALESCE(SUM('.EntryDuration::secondsSql().'), 0) AS s')
            ->groupBy('project_id')
            ->pluck('s', 'project_id');

        $overBudget = $budgeted->filter(function (Project $p) use ($secsByProject) {
            $hours = round(((int) ($secsByProject[$p->id] ?? 0)) / 3600, 2);

            return (int) round(($hours / $p->budget_hours) * 100) > 100;
        })->count();

        return [
            'month_hours' => round($monthSecs / 3600, 1),
            'unbilled_rappen' => (int) round((float) $unbilled->rappen),
            'unbilled_hours' => round(((int) $unbilled->secs) / 3600, 1),
            'over_budget_count' => $overBudget,
        ];
    }
}
