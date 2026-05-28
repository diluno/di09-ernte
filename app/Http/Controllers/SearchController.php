<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());
        $type = $request->string('type')->toString();
        $allowed = ['project', 'client', 'invoice'];
        $type = in_array($type, $allowed, true) ? $type : null;

        $results = collect();

        if (! $type || $type === 'project') {
            $results = $results->merge($this->projects($query, $type ? 8 : 4));
        }

        if (! $type || $type === 'client') {
            $results = $results->merge($this->clients($query, $type ? 8 : 2));
        }

        if (! $type || $type === 'invoice') {
            $results = $results->merge($this->invoices($query, $type ? 8 : 4));
        }

        return response()->json($results->take(8)->values());
    }

    private function projects(string $query, int $limit): array
    {
        return Project::query()
            ->with('client:id,name')
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('projects.name', 'like', "%{$query}%")
                    ->orWhere('projects.code', 'like', "%{$query}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$query}%"));
            }))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Project $project) => [
                'type' => 'project',
                'id' => $project->id,
                'label' => $project->name,
                'sublabel' => "{$project->code} · {$project->client->name}",
                'url' => "/projects/{$project->code}",
            ])
            ->all();
    }

    private function clients(string $query, int $limit): array
    {
        return Client::query()
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('name', 'like', "%{$query}%")
                    ->orWhere('short_code', 'like', "%{$query}%")
                    ->orWhere('contact_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            }))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Client $client) => [
                'type' => 'client',
                'id' => $client->id,
                'label' => $client->name,
                'sublabel' => trim(implode(' · ', array_filter([$client->contact_name, $client->email]))),
                'url' => "/clients/{$client->id}",
            ])
            ->all();
    }

    private function invoices(string $query, int $limit): array
    {
        return Invoice::query()
            ->with('client:id,name')
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('number', 'like', "%{$query}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$query}%"));
            }))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'type' => 'invoice',
                'id' => $invoice->id,
                'label' => "#{$invoice->number}",
                'sublabel' => "{$invoice->client->name} · {$invoice->status}",
                'url' => "/invoices/{$invoice->number}",
            ])
            ->all();
    }
}
