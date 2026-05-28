<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class ClientDetail
{
    public static function payload(Client $client): array
    {
        $projectIds = $client->projects()->pluck('id');

        return [
            'client' => self::client($client),
            'stats' => self::stats($client, $projectIds),
            'projects' => self::projects($client),
            'recent_entries' => self::recentEntries($projectIds),
            'invoices' => self::invoices($client),
        ];
    }

    private static function client(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'short_code' => $client->short_code,
            'contact_name' => $client->contact_name,
            'email' => $client->email,
            'address_line_1' => $client->address_line_1,
            'address_line_2' => $client->address_line_2,
            'postal_code' => $client->postal_code,
            'city' => $client->city,
            'country' => $client->country,
            'vat_id' => $client->vat_id,
            'default_rate' => $client->default_rate_rappen ? (int) round($client->default_rate_rappen / 100) : null,
            'archived' => $client->archived_at !== null,
        ];
    }

    private static function stats(Client $client, $projectIds): array
    {
        $yearStart = Carbon::now()->startOfYear();
        $secondsYtd = (int) TimeEntry::query()
            ->whereIn('project_id', $projectIds)
            ->where('started_at', '>=', $yearStart)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs')
            ->value('secs');

        $unbilled = TimeEntry::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('invoice_id')
            ->where('billable', true)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs')
            ->value('secs');

        $outstanding = (int) Invoice::query()
            ->where('client_id', $client->id)
            ->where('status', 'sent')
            ->sum('total_rappen');

        $paidYtd = (int) Invoice::query()
            ->where('client_id', $client->id)
            ->where('status', 'paid')
            ->whereYear('paid_at', Carbon::now()->year)
            ->sum('total_rappen');

        return [
            'projects' => $client->projects()->count(),
            'active_projects' => $client->projects()->where('status', 'active')->count(),
            'hours_ytd' => round($secondsYtd / 3600, 1),
            'unbilled_hours' => round(((int) $unbilled) / 3600, 1),
            'outstanding' => round($outstanding / 100, 2),
            'paid_ytd' => round($paidYtd / 100, 2),
        ];
    }

    private static function projects(Client $client): array
    {
        return Project::query()
            ->where('client_id', $client->id)
            ->orderByRaw("FIELD(status, 'active', 'archived')")
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Project $project) {
                $seconds = (int) TimeEntry::query()
                    ->where('project_id', $project->id)
                    ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs')
                    ->value('secs');
                $hours = round($seconds / 3600, 2);
                $pct = $project->budget_hours > 0 ? (int) round(($hours / $project->budget_hours) * 100) : 0;
                $band = $pct > 100 ? 'over' : ($pct >= 85 ? 'warn' : 'ok');

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                    'billable' => (bool) $project->billable,
                    'rate' => (int) round($project->rate_rappen / 100),
                    'budget_hours' => (int) $project->budget_hours,
                    'spent_hours' => $hours,
                    'pct_hours' => $pct,
                    'band' => $band,
                ];
            })
            ->all();
    }

    private static function recentEntries($projectIds): array
    {
        return TimeEntry::query()
            ->whereIn('project_id', $projectIds)
            ->with(['project:id,name,code', 'task:id,name'])
            ->orderByDesc('started_at')
            ->limit(8)
            ->get()
            ->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'description' => $entry->description,
                'task_name' => $entry->task?->name,
                'started_at' => $entry->started_at->toIso8601String(),
                'ended_at' => $entry->ended_at?->toIso8601String(),
                'duration_seconds' => $entry->duration_seconds,
                'billable' => (bool) $entry->billable,
                'running' => $entry->ended_at === null,
                'project' => [
                    'id' => $entry->project->id,
                    'name' => $entry->project->name,
                    'code' => $entry->project->code,
                ],
            ])
            ->all();
    }

    private static function invoices(Client $client): array
    {
        return Invoice::query()
            ->where('client_id', $client->id)
            ->with('lines:id,invoice_id,hours')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'overdue' => $invoice->overdue,
                'issued_on' => $invoice->issued_on?->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'hours' => round((float) $invoice->lines->sum('hours'), 1),
                'total' => round($invoice->total_rappen / 100, 2),
                'url' => "/invoices/{$invoice->number}",
            ])
            ->all();
    }
}
