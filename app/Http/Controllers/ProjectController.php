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
        // Implemented in Task 6.
        abort(501, 'Project show not yet implemented');
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());
        return back();
    }

    public function archive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'archived']);
        return back();
    }
}
