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
