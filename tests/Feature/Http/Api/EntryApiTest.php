<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
});

test('POST /api/entries requires authentication', function () {
    $this->postJson('/api/entries', [])->assertUnauthorized();
});

test('POST /api/entries creates a finished entry for the authenticated user', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'description' => 'Logged from iOS',
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
        'billable'   => true,
    ])->assertCreated()->assertJsonStructure(['id']);

    $entry = TimeEntry::first();
    expect($entry->user_id)->toBe($this->user->id);
    expect($entry->project_id)->toBe($this->project->id);
    expect($entry->description)->toBe('Logged from iOS');
    expect($entry->duration_seconds)->toBe(5400);
});

test('POST /api/entries with a blank description stores an empty string', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'description' => null,
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:00:00Z',
        'billable'   => true,
    ])->assertCreated();

    expect(TimeEntry::first()->description)->toBe('');
});

test('POST /api/entries rejects ended_at not after started_at', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'started_at' => '2026-05-27T10:00:00Z',
        'ended_at'   => '2026-05-27T09:00:00Z',
        'billable'   => true,
    ])->assertStatus(422)->assertJsonValidationErrors('ended_at');
});
