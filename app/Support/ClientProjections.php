<?php

namespace App\Support;

use App\Models\Client;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClientProjections
{
    public static function index(): Collection
    {
        $clients = Client::query()
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        $yearStart = Carbon::now()->startOfYear();

        $hoursYtd = TimeEntry::query()
            ->where('started_at', '>=', $yearStart)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                projects.client_id,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs
            ')
            ->groupBy('projects.client_id')
            ->pluck('secs', 'projects.client_id');

        return $clients->map(fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'short_code' => $c->short_code,
            'contact_name' => $c->contact_name,
            'email' => $c->email,
            'default_rate' => $c->default_rate_rappen ? (int) round($c->default_rate_rappen / 100) : null,
            'projects_count' => (int) $c->projects_count,
            'hours_ytd' => round(((int) ($hoursYtd[$c->id] ?? 0)) / 3600, 1),
            'outstanding' => 0,                             // Phase 2b
            'archived' => $c->archived_at !== null,
        ]);
    }
}
