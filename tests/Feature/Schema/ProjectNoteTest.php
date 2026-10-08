<?php

use App\Models\Project;
use App\Models\ProjectNote;

test('a project has notes, newest first', function () {
    $project = Project::factory()->create();
    $old = ProjectNote::factory()->create(['project_id' => $project->id, 'created_at' => now()->subDay()]);
    $new = ProjectNote::factory()->create(['project_id' => $project->id]);

    expect($project->notes->pluck('id')->all())->toBe([$new->id, $old->id]);
    expect($new->project->is($project))->toBeTrue();
});

test('deleting a project deletes its notes', function () {
    $note = ProjectNote::factory()->create();

    $note->project->delete();

    expect(ProjectNote::find($note->id))->toBeNull();
});
