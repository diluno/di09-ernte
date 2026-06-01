<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
});

test('GET /api/timer requires authentication', function () {
    $this->getJson('/api/timer')->assertUnauthorized();
});

test('GET /api/timer returns today payload with a running key', function () {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/timer')
        ->assertOk()
        ->assertJsonStructure([
            'entries',
            'totals' => ['total_seconds', 'billable_seconds', 'earnings_amount'],
            'by_project',
            'quick_start',
            'projects',
            'running',
        ])
        ->assertJson(['running' => null]);
});

test('GET /api/timer reports the running entry', function () {
    Sanctum::actingAs($this->user);
    TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'description' => 'in progress',
        'started_at' => now()->subMinutes(10),
        'ended_at' => null,
        'billable' => true,
    ]);

    $this->getJson('/api/timer')
        ->assertOk()
        ->assertJson([
            'running' => [
                'description' => 'in progress',
                'project' => ['id' => $this->project->id],
            ],
        ]);
});
