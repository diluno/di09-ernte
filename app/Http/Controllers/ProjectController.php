<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Support\DashboardProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'stats'    => DashboardProjections::stats($request->user()),
            'counts'   => [
                'active'   => Project::active()->count(),
                'all'      => Project::count(),
                'archived' => Project::archived()->count(),
            ],
            'filters'  => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());
        return redirect("/projects/{$project->code}");
    }

    public function show(Project $project): Response
    {
        return Inertia::render('Projects/Show', \App\Support\ProjectDetail::payload($project));
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());
        return back();
    }

    public function archive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'archived', 'pinned_at' => null]);
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
