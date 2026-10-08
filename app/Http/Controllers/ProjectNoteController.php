<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectNoteRequest;
use App\Models\Project;
use App\Models\ProjectNote;
use Illuminate\Http\RedirectResponse;

class ProjectNoteController extends Controller
{
    public function store(ProjectNoteRequest $request, Project $project): RedirectResponse
    {
        $project->notes()->create($request->validated());

        return back();
    }

    public function update(ProjectNoteRequest $request, ProjectNote $projectNote): RedirectResponse
    {
        $projectNote->update($request->validated());

        return back();
    }

    public function destroy(ProjectNote $projectNote): RedirectResponse
    {
        $projectNote->delete();

        return back();
    }
}
