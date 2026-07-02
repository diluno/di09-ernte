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
            ->with('contacts')
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

        $outstanding = InvoiceProjections::outstandingByClient();

        $spark = self::activitySparkline($clients->pluck('id'));

        return $clients->map(fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'short_code' => $c->short_code,
            'default_contact' => $c->defaultRecipients()[0] ?? null,
            'default_rate' => $c->default_rate_rappen ? (int) round($c->default_rate_rappen / 100) : null,
            'projects_count' => (int) $c->projects_count,
            'hours_ytd' => round(((int) ($hoursYtd[$c->id] ?? 0)) / 3600, 1),
            'outstanding' => round(((int) ($outstanding[$c->id] ?? 0)) / 100, 2),
            'sparkline' => $spark[$c->id] ?? array_fill(0, 14, 0),
            'archived' => $c->archived_at !== null,
        ]);
    }

    private static function activitySparkline(Collection $clientIds): array
    {
        $start = Carbon::today()->subDays(13);
        $rows = TimeEntry::query()
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->whereIn('projects.client_id', $clientIds)
            ->where('time_entries.started_at', '>=', $start)
            ->selectRaw('
                projects.client_id,
                DATE(time_entries.started_at) AS day,
                SUM(TIMESTAMPDIFF(SECOND, time_entries.started_at, COALESCE(time_entries.ended_at, UTC_TIMESTAMP()))) AS secs
            ')
            ->groupBy('projects.client_id', 'day')
            ->get()
            ->groupBy('client_id');

        $series = [];

        foreach ($clientIds as $clientId) {
            $byDay = ($rows->get($clientId) ?? collect())->keyBy('day');
            $series[$clientId] = [];

            for ($i = 0; $i < 14; $i++) {
                $key = $start->copy()->addDays($i)->toDateString();
                $series[$clientId][] = round(((int) ($byDay->get($key)->secs ?? 0)) / 3600, 1);
            }
        }

        return $series;
    }
}
