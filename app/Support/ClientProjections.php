<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Invoice;
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
                COALESCE(SUM('.EntryDuration::secondsSql().'), 0) AS secs
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
            'default_rate' => $c->default_rate_rappen ? round($c->default_rate_rappen / 100, 2) : null,
            'projects_count' => (int) $c->projects_count,
            'hours_ytd' => round(((int) ($hoursYtd[$c->id] ?? 0)) / 3600, 1),
            'outstanding' => round(((int) ($outstanding[$c->id] ?? 0)) / 100, 2),
            'sparkline' => $spark[$c->id] ?? array_fill(0, 14, 0),
            'archived' => $c->archived_at !== null,
        ]);
    }

    /**
     * Stats strip on /clients. Money is integer rappen.
     * Unbilled = billable, not yet invoiced time valued at each project's rate.
     */
    public static function stats(): array
    {
        $outstanding = Invoice::outstanding()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_rappen), 0) AS rappen')
            ->first();

        $overdueCount = Invoice::outstanding()
            ->whereDate('due_on', '<', Carbon::today())
            ->count();

        $unbilled = TimeEntry::query()
            ->whereNull('time_entries.invoice_id')
            ->where('time_entries.billable', true)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                COALESCE(SUM('.EntryDuration::secondsSql('time_entries').' * projects.rate_rappen) / 3600, 0) AS rappen,
                COALESCE(SUM('.EntryDuration::secondsSql('time_entries').'), 0) AS secs,
                COUNT(DISTINCT projects.client_id) AS clients
            ')
            ->first();

        $secsYtd = (int) TimeEntry::query()
            ->where('started_at', '>=', Carbon::now()->startOfYear())
            ->selectRaw('COALESCE(SUM('.EntryDuration::secondsSql().'), 0) AS s')
            ->value('s');

        return [
            'active' => Client::active()->count(),
            'archived' => Client::archived()->count(),
            'outstanding_rappen' => (int) $outstanding->rappen,
            'outstanding_count' => (int) $outstanding->n,
            'overdue_count' => $overdueCount,
            'unbilled_rappen' => (int) round((float) $unbilled->rappen),
            'unbilled_hours' => round(((int) $unbilled->secs) / 3600, 1),
            'unbilled_clients' => (int) $unbilled->clients,
            'hours_ytd' => round($secsYtd / 3600, 1),
        ];
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
                SUM('.EntryDuration::secondsSql('time_entries').') AS secs
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
