<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
    $this->actingAs($this->user);
});

test('POST /entries creates a finished entry', function () {
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'description' => 'Yesterday session',
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
        'billable'   => true,
    ])->assertRedirect();

    $entry = TimeEntry::first();
    expect($entry->description)->toBe('Yesterday session');
    expect($entry->duration_seconds)->toBe(5400);
    expect($entry->ended_at)->not->toBeNull();
});

test('POST /entries with no description stores an empty string (not null)', function () {
    // Laravel's ConvertEmptyStringsToNull turns a blank description into null;
    // the column is NOT NULL, so the controller must fall back to ''.
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'description' => null,
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:00:00Z',
        'billable'   => true,
    ])->assertRedirect();

    expect(TimeEntry::first()->description)->toBe('');
});

test('POST /entries preserves the submitted instant across the app timezone', function () {
    // The browser sends a true UTC instant (toISOString → ...Z). The DATETIME column
    // stores app-local (Europe/Zurich) wall-clock, so the request must convert the
    // instant to app tz before storing — otherwise the offset is silently stripped and
    // every edit-save shifts the time back by the UTC offset.
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
        'billable'   => true,
    ])->assertRedirect();

    $entry = TimeEntry::first();
    expect($entry->started_at->equalTo('2026-05-27T09:00:00Z'))->toBeTrue();
    expect($entry->ended_at->equalTo('2026-05-27T10:30:00Z'))->toBeTrue();
    // 09:00Z is 11:00 in Zurich summer time (CEST, +02:00).
    expect($entry->started_at->format('H:i'))->toBe('11:00');
});

test('PATCH /entries/{id} preserves the submitted instant (no round-trip drift)', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true, 'description' => 'old',
    ]);

    $this->patch("/entries/{$e->id}", [
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($e->fresh()->started_at->equalTo('2026-05-27T09:00:00Z'))->toBeTrue();
    expect($e->fresh()->ended_at->equalTo('2026-05-27T10:30:00Z'))->toBeTrue();
});

test('POST /entries rejects ended_at before started_at', function () {
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'started_at' => '2026-05-27T10:00:00Z',
        'ended_at'   => '2026-05-27T09:00:00Z',
        'billable'   => true,
    ])->assertSessionHasErrors('ended_at');
});

test('POST /entries with no ended_at would create a second running entry — rejected', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now(), 'ended_at' => null, 'billable' => true, 'description' => 'already running',
    ]);

    $this->post('/entries', [
        'project_id' => $this->project->id,
        'started_at' => now()->toIso8601String(),
        'billable'   => true,
    ])->assertSessionHasErrors('ended_at');
});

test('PATCH /entries/{id} updates fields', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true, 'description' => 'old',
    ]);

    $this->patch("/entries/{$e->id}", ['description' => 'updated'])
        ->assertRedirect();

    expect($e->fresh()->description)->toBe('updated');
});

test('PATCH /entries/{id} with a blank description stores an empty string (not null)', function () {
    // ConvertEmptyStringsToNull turns a cleared field into null; the column is
    // NOT NULL, so update() must coalesce to '' like store() does.
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true, 'description' => 'had text',
    ]);

    $this->patch("/entries/{$e->id}", ['description' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($e->fresh()->description)->toBe('');
});

test('DELETE /entries/{id} removes the entry', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true,
    ]);

    $this->delete("/entries/{$e->id}")->assertRedirect();
    expect(TimeEntry::find($e->id))->toBeNull();
});

test('PATCH/DELETE /entries/{id} owned by another user is forbidden', function () {
    $other = User::factory()->create();
    $e = TimeEntry::create([
        'user_id' => $other->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true, 'description' => 'theirs',
    ]);

    $this->patch("/entries/{$e->id}", ['description' => 'mine now'])->assertForbidden();
    $this->delete("/entries/{$e->id}")->assertForbidden();

    expect($e->fresh()->description)->toBe('theirs');
    expect(TimeEntry::find($e->id))->not->toBeNull();
});

test('POST /entries rejects task_id that belongs to a different project', function () {
    $otherProject = Project::factory()->create(['billable' => true]);
    $foreignTask = Task::factory()->create(['project_id' => $otherProject->id]);

    $this->post('/entries', [
        'project_id' => $this->project->id,
        'task_id'    => $foreignTask->id,
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:00:00Z',
        'billable'   => true,
    ])->assertSessionHasErrors('task_id');
});
