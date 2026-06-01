<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/dashboard requires authentication', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized();
});

test('GET /api/dashboard returns timer, hours and money blocks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->setTime(9, 0),
        'ended_at' => now()->setTime(11, 0),
        'billable' => true,
    ]);
    Sanctum::actingAs($user);

    $res = $this->getJson('/api/dashboard')->assertOk();

    $res->assertJsonStructure([
        'timer' => ['running', 'today' => ['total_seconds', 'billable_seconds', 'earnings_amount']],
        'hours' => ['week_hours', 'sparkline'],
        'money' => ['outstanding', 'overdue', 'unbilled'],
    ]);
    expect($res->json('hours.sparkline'))->toHaveCount(7);
    expect($res->json('timer.running'))->toBeNull();
    expect($res->json('timer.today.total_seconds'))->toBe(7200);
});

test('GET /api/dashboard surfaces the running entry', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->subMinutes(30),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('timer.running.project.code', $project->code);
});
