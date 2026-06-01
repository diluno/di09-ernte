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

test('POST /api/timer/start creates a running entry and returns it', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/timer/start', [
        'project_id' => $this->project->id,
        'description' => 'kick off',
    ])
        ->assertOk()
        ->assertJson(['running' => ['description' => 'kick off']]);

    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('POST /api/timer/start auto-stops the previous entry', function () {
    Sanctum::actingAs($this->user);
    $other = Project::factory()->create();

    $this->postJson('/api/timer/start', ['project_id' => $other->id]);
    $first = TimeEntry::running()->first();

    $this->postJson('/api/timer/start', ['project_id' => $this->project->id])->assertOk();

    expect(TimeEntry::running()->count())->toBe(1);
    expect($first->fresh()->ended_at)->not->toBeNull();
});

test('POST /api/timer/start validates task ownership', function () {
    Sanctum::actingAs($this->user);
    $otherProject = Project::factory()->create();
    $task = \App\Models\Task::create(['project_id' => $otherProject->id, 'name' => 'x', 'sort_order' => 0]);

    $this->postJson('/api/timer/start', [
        'project_id' => $this->project->id,
        'task_id' => $task->id,
    ])->assertStatus(422)->assertJsonValidationErrors('task_id');
});

test('POST /api/timer/switch behaves like start', function () {
    Sanctum::actingAs($this->user);
    $other = Project::factory()->create();
    $this->postJson('/api/timer/start', ['project_id' => $other->id]);

    $this->postJson('/api/timer/switch', [
        'project_id' => $this->project->id,
        'description' => 'new context',
    ])->assertOk()->assertJson(['running' => ['description' => 'new context']]);

    expect(TimeEntry::running()->first()->project_id)->toBe($this->project->id);
});

test('POST /api/timer/stop ends the running entry', function () {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/timer/start', ['project_id' => $this->project->id]);

    $this->postJson('/api/timer/stop')
        ->assertOk()
        ->assertJson(['running' => null]);

    expect(TimeEntry::running()->count())->toBe(0);
});

test('POST /api/timer/discard deletes the running entry', function () {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/timer/start', ['project_id' => $this->project->id]);
    $id = TimeEntry::running()->first()->id;

    $this->postJson('/api/timer/discard')
        ->assertOk()
        ->assertJson(['running' => null]);

    expect(TimeEntry::find($id))->toBeNull();
});
