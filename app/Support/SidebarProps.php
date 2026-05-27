<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

class SidebarProps
{
    public static function runningEntry(User $user): ?array
    {
        $entry = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with(['project:id,name,code,glyph,rate_rappen', 'task:id,name'])
            ->first();

        if (! $entry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'description' => $entry->description,
            'started_at' => $entry->started_at->toIso8601String(),
            'billable' => (bool) $entry->billable,
            'project' => [
                'id' => $entry->project->id,
                'name' => $entry->project->name,
                'code' => $entry->project->code,
                'glyph' => $entry->project->glyph,
                'rate_rappen' => (int) $entry->project->rate_rappen,
            ],
            'task' => $entry->task ? [
                'id' => $entry->task->id,
                'name' => $entry->task->name,
            ] : null,
        ];
    }

    public static function sidebar(User $user): array
    {
        return [
            'nav_counts' => [
                'projects' => Project::active()->count(),
                'clients'  => Client::active()->count(),
            ],
            'pinned' => self::pinnedProjects(),
            'week_hours' => self::weekHours($user),
            'today_hours' => self::todaySeconds($user) / 3600,
        ];
    }

    /** Top 4 active projects ordered by last activity (most recent entry's started_at). */
    private static function pinnedProjects(): array
    {
        return Project::active()
            ->select('projects.id', 'projects.name', 'projects.code', 'projects.glyph')
            ->leftJoin('time_entries', 'time_entries.project_id', '=', 'projects.id')
            ->groupBy('projects.id', 'projects.name', 'projects.code', 'projects.glyph')
            ->orderByRaw('COALESCE(MAX(time_entries.started_at), projects.created_at) DESC')
            ->limit(4)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'glyph' => $p->glyph,
            ])
            ->all();
    }

    /** Returns [mon, tue, wed, thu, fri, sat, sun] in hours, for the current ISO-week. */
    private static function weekHours(User $user): array
    {
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $rows = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$monday, $monday->copy()->addDays(7)])
            ->selectRaw('
                WEEKDAY(started_at) AS dow,
                SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))) AS secs
            ')
            ->groupBy('dow')
            ->pluck('secs', 'dow');

        // WEEKDAY: Monday=0..Sunday=6
        $week = array_fill(0, 7, 0.0);
        foreach ($rows as $dow => $secs) {
            $week[(int) $dow] = round(((int) $secs) / 3600, 1);
        }
        return $week;
    }

    private static function todaySeconds(User $user): int
    {
        return (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('started_at', Carbon::today())
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s')
            ->value('s');
    }
}
