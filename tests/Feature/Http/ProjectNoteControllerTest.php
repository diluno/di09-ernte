<?php

use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\User;

beforeEach(function () {
    $this->project = Project::factory()->create(['code' => 'ATLS-FLT']);
    $this->actingAs(User::factory()->create());
});

test('POST /projects/{code}/notes adds a note to the project', function () {
    $this->post('/projects/ATLS-FLT/notes', ['body' => "Call with **Mara**\n- staging by Friday"])
        ->assertRedirect();

    expect($this->project->notes()->sole()->body)->toBe("Call with **Mara**\n- staging by Friday");
});

test('POST /projects/{code}/notes rejects an empty body', function () {
    $this->post('/projects/ATLS-FLT/notes', ['body' => ''])->assertSessionHasErrors('body');
    $this->post('/projects/ATLS-FLT/notes', ['body' => "  \n "])->assertSessionHasErrors('body');

    expect(ProjectNote::count())->toBe(0);
});

test('PATCH /project-notes/{id} changes the body and keeps the date', function () {
    $note = ProjectNote::factory()->create(['project_id' => $this->project->id, 'created_at' => '2026-09-01 10:00:00']);

    $this->patch("/project-notes/{$note->id}", ['body' => 'Rewritten'])->assertRedirect();

    expect($note->fresh()->body)->toBe('Rewritten');
    expect($note->fresh()->created_at->toDateTimeString())->toBe('2026-09-01 10:00:00');
});

test('PATCH /project-notes/{id} rejects an empty body', function () {
    $note = ProjectNote::factory()->create(['body' => 'keep me']);

    $this->patch("/project-notes/{$note->id}", ['body' => ''])->assertSessionHasErrors('body');

    expect($note->fresh()->body)->toBe('keep me');
});

test('DELETE /project-notes/{id} deletes the note', function () {
    $note = ProjectNote::factory()->create();

    $this->delete("/project-notes/{$note->id}")->assertRedirect();

    expect(ProjectNote::find($note->id))->toBeNull();
});

test('note routes require login', function () {
    auth()->logout();

    $this->post('/projects/ATLS-FLT/notes', ['body' => 'x'])->assertRedirect('/login');
});
