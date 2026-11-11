<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Client;
use App\Models\Project;
use App\Support\DashboardProjections;
use App\Support\ProjectDetail;
use App\Support\ProjectProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'active')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Projects/Index', [
            'projects' => DashboardProjections::projects($filter, $search)->values(),
            // DashboardProjections::stats is shared with the API; the web strip adds its own figures.
            'stats' => [
                ...DashboardProjections::stats($request->user()),
                ...ProjectProjections::indexStats($request->user()),
            ],
            'counts' => [
                'active' => Project::active()->count(),
                'all' => Project::count(),
                'archived' => Project::archived()->count(),
            ],
            'filters' => ['filter' => $filter, 'q' => $search],
        ]);
    }

    private function activeClients(): Collection
    {
        return Client::active()->orderBy('name')->get(['id', 'name']);
    }

    public function create(Request $request): Response
    {
        $clients = $this->activeClients();

        return Inertia::render('Projects/Create', [
            'clients' => $clients,
            // "New project" from a client page preselects that client.
            'client_id' => $clients->firstWhere('id', $request->integer('client'))?->id,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        return redirect("/projects/{$project->code}");
    }

    public function show(Project $project): Response
    {
        return Inertia::render('Projects/Show', ProjectDetail::payload($project));
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('Projects/Edit', [
            'project' => [
                'id' => $project->id,
                'client_id' => $project->client_id,
                'name' => $project->name,
                'code' => $project->code,
                'description' => $project->description,
                'billable' => (bool) $project->billable,
                'budget_hours' => (int) $project->budget_hours,
                'budget_amount' => round($project->budget_amount_rappen / 100, 2),
                'rate' => round($project->rate_rappen / 100, 2),
                'started_on' => $project->started_on?->toDateString(),
                'deadline_on' => $project->deadline_on?->toDateString(),
                'status' => $project->status,
            ],
            'clients' => $this->activeClients(),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect("/projects/{$project->code}");
    }

    public function archive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'archived', 'pinned_at' => null]);

        return back();
    }

    public function unarchive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'active']);

        return back();
    }

    public function pin(Project $project): RedirectResponse
    {
        if ($project->pinned_at === null) {
            $project->update(['pinned_at' => now()]);
        }

        return back();
    }

    public function unpin(Project $project): RedirectResponse
    {
        $project->update(['pinned_at' => null]);

        return back();
    }
}
