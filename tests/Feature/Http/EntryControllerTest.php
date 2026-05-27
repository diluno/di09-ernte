<?php

use App\Models\Project;
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

test('DELETE /entries/{id} removes the entry', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true,
    ]);

    $this->delete("/entries/{$e->id}")->assertRedirect();
    expect(TimeEntry::find($e->id))->toBeNull();
});
