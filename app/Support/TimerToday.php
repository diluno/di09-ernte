<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

class TimerToday
{
    public static function payload(User $user, ?Carbon $date = null): array
    {
        $start = ($date ?? Carbon::today())->copy()->startOfDay();
        $end = $start->copy()->addDay();

        $entries = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$start, $end])
            ->with(['project:id,name,code,rate_rappen', 'task:id,name'])
            ->orderBy('started_at')
            ->get();

        $totalSecs = 0;
        $billableSecs = 0;
        $earningsRappen = 0;

        $serialized = $entries->map(function (TimeEntry $e) use (&$totalSecs, &$billableSecs, &$earningsRappen) {
            $dur = $e->duration_seconds;
            $totalSecs += $dur;
            if ($e->billable) {
                $billableSecs += $dur;
                $earningsRappen += (int) round(($dur / 3600) * (int) $e->project->rate_rappen);
            }

            return [
                'id' => $e->id,
                'description' => $e->description,
                'task_name' => $e->task?->name,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_seconds' => $dur,
                'billable' => (bool) $e->billable,
                'running' => $e->ended_at === null,
                'project' => [
                    'id' => $e->project->id,
                    'name' => $e->project->name,
                    'code' => $e->project->code,
                ],
            ];
        });

        $byProject = $entries->groupBy('project_id')->map(function ($bucket) {
            $first = $bucket->first();
            $secs = $bucket->sum(fn (TimeEntry $e) => $e->duration_seconds);

            return [
                'project_id' => $first->project_id,
                'name' => $first->project->name,
                'code' => $first->project->code,
                'seconds' => (int) $secs,
            ];
        })->values()->all();

        $quickStart = Project::active()
            ->select('id', 'name', 'code')
            ->orderByDesc('updated_at')
            ->limit(4)
            ->get()
            ->all();

        // Full active-project list for the manual-entry picker (any project is trackable,
        // budget or not). quick_start stays the recent-4 shortcut buttons.
        $projects = Project::active()
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get()
            ->all();

        return [
            'date' => $start->toDateString(),
            'is_today' => $start->isToday(),
            'entries' => $serialized->all(),
            'totals' => [
                'total_seconds' => $totalSecs,
                'billable_seconds' => $billableSecs,
                'earnings_amount' => round($earningsRappen / 100, 2),
            ],
            'by_project' => $byProject,
            'quick_start' => $quickStart,
            'projects' => $projects,
        ];
    }
}
