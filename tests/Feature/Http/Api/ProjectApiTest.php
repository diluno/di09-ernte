<?php

use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/projects requires authentication', function () {
    $this->getJson('/api/projects')->assertUnauthorized();
});

test('GET /api/projects returns projects, stats and counts', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->archived()->create(['name' => 'Old One']);

    $this->getJson('/api/projects')
        ->assertOk()
        ->assertJsonStructure([
            'projects' => [['id', 'code', 'name', 'status', 'spent_hours', 'pct_hours', 'band', 'sparkline', 'client' => ['id', 'name']]],
            'stats' => ['active', 'week_hours', 'unbilled_amount', 'outstanding_amount'],
            'counts' => ['active', 'all', 'archived'],
        ])
        ->assertJsonPath('counts.all', 2)
        ->assertJsonPath('counts.active', 1)
        ->assertJsonPath('counts.archived', 1)
        ->assertJsonCount(1, 'projects');
});

test('GET /api/projects?filter=archived narrows to archived', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->archived()->create(['name' => 'Old One']);

    $this->getJson('/api/projects?filter=archived')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Old One');
});

test('GET /api/projects?q= filters by name', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Findable']);
    Project::factory()->create(['name' => 'Hidden']);

    $this->getJson('/api/projects?q=Find')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Findable');
});

test('GET /api/projects/{code} requires authentication', function () {
    $project = Project::factory()->create(['code' => 'ACME-001']);
    $this->getJson("/api/projects/{$project->code}")->assertUnauthorized();
});

test('GET /api/projects/{code} returns the project detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $project = Project::factory()->create(['name' => 'Acme Site', 'code' => 'ACME-001']);

    $this->getJson("/api/projects/{$project->code}")
        ->assertOk()
        ->assertJsonStructure([
            'project' => ['id', 'name', 'code', 'status', 'spent_hours', 'pct_hours', 'band', 'client' => ['id', 'name']],
            'tasks',
            'recent_entries',
            'heatmap',
            'counts' => ['entries', 'tasks'],
        ])
        ->assertJsonPath('project.code', 'ACME-001')
        ->assertJsonPath('project.name', 'Acme Site');
});

test('GET /api/projects/{code} returns 404 for an unknown code', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/projects/NOPE-999')->assertNotFound();
});
