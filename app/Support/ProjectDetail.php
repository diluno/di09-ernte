<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class ProjectDetail
{
    public static function payload(Project $project): array
    {
        $project->load('client:id,name,short_code');

        $secs = (int) TimeEntry::query()
            ->where('project_id', $project->id)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s')
            ->value('s');
        $hours = round($secs / 3600, 2);
        $amount = (int) round($hours * (int) $project->rate_rappen);
        $pct = $project->budget_hours > 0 ? (int) round(($hours / $project->budget_hours) * 100) : 0;
        $band = $pct > 100 ? 'over' : ($pct >= 85 ? 'warn' : 'ok');

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'glyph' => $project->glyph,
                'status' => $project->status,
                'description' => $project->description,
                'billable' => (bool) $project->billable,
                'retainer' => (bool) $project->retainer,
                'rate' => (int) round($project->rate_rappen / 100),
                'budget_hours' => (int) $project->budget_hours,
                'budget_amount' => (int) round($project->budget_amount_rappen / 100),
                'spent_hours' => $hours,
                'spent_amount' => round($amount / 100, 2),
                'pct_hours' => $pct,
                'band' => $band,
                'started_on' => $project->started_on?->toDateString(),
                'deadline_on' => $project->deadline_on?->toDateString(),
                'client' => [
                    'id' => $project->client->id,
                    'name' => $project->client->name,
                ],
            ],
            'tasks' => self::tasks($project),
            'recent_entries' => self::recentEntries($project, limit: 8),
            'heatmap' => self::heatmap($project),
            'counts' => [
                'entries' => TimeEntry::where('project_id', $project->id)->count(),
                'tasks'   => Task::where('project_id', $project->id)->count(),
            ],
        ];
    }

    private static function tasks(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $spent = TimeEntry::query()
            ->where('project_id', $project->id)
            ->whereNotNull('task_id')
            ->selectRaw('
                task_id,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s
            ')
            ->groupBy('task_id')
            ->pluck('s', 'task_id');

        return $tasks->map(fn (Task $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'done' => (bool) $t->done,
            'budget_hours' => (int) ($t->budget_hours ?? 0),
            'spent_hours' => round(((int) ($spent[$t->id] ?? 0)) / 3600, 2),
            'sort_order' => (int) $t->sort_order,
        ])->all();
    }

    private static function recentEntries(Project $project, int $limit): array
    {
        return TimeEntry::query()
            ->where('project_id', $project->id)
            ->with('task:id,name')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'description' => $e->description,
                'task_name' => $e->task?->name,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_seconds' => $e->duration_seconds,
                'billable' => (bool) $e->billable,
                'running' => $e->ended_at === null,
            ])
            ->all();
    }

    /** 60 cells, hours/day, oldest → newest (today is the last cell). */
    private static function heatmap(Project $project): array
    {
        $start = Carbon::today()->subDays(59);

        $byDay = TimeEntry::query()
            ->where('project_id', $project->id)
            ->where('started_at', '>=', $start)
            ->selectRaw('
                DATE(started_at) AS day,
                SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))) AS s
            ')
            ->groupBy('day')
            ->pluck('s', 'day');

        $cells = [];
        for ($i = 0; $i < 60; $i++) {
            $key = $start->copy()->addDays($i)->toDateString();
            $cells[] = round(((int) ($byDay[$key] ?? 0)) / 3600, 1);
        }
        return $cells;
    }
}
