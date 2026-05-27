<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('GET /projects renders Projects/Index with the project list', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT', 'status' => 'active',
        'budget_hours' => 220, 'budget_amount_rappen' => 31900_00,
        'rate_rappen' => 14500,
    ]);
    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'description' => 'work', 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        'billable' => true,
    ]);

    $this->actingAs($user)->get('/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->has('projects', 1, fn (Assert $p) => $p
                ->where('code', 'ATLS-FLT')
                ->where('name', 'Fleet Console v2')
                ->where('client.name', 'Atlas Robotics')
                ->where('budget_hours', 220)
                ->where('budget_amount', 31900)              // CHF, not rappen
                ->where('rate', 145)                         // CHF/h
                ->has('spent_hours')
                ->has('spent_amount')
                ->has('band')
                ->has('sparkline', 14)                       // 14 numbers
                ->etc()
            )
            ->has('stats', fn (Assert $s) => $s
                ->where('active', 1)
                ->has('week_hours')
                ->has('unbilled_amount')
                ->has('outstanding_amount')                  // 0 in Phase 2a
                ->etc()
            )
            ->has('counts', fn (Assert $c) => $c
                ->where('active', 1)->where('all', 1)->where('archived', 0)
            )
        );
});

test('GET /projects filter=archived excludes active projects', function () {
    $user = User::factory()->create();
    Project::factory()->create(['status' => 'active']);
    Project::factory()->create(['status' => 'archived']);

    $this->actingAs($user)->get('/projects?filter=archived')
        ->assertInertia(fn (Assert $page) => $page->has('projects', 1));
});

test('POST /projects creates a new project', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $this->actingAs($user)->post('/projects', [
        'client_id' => $client->id,
        'name' => 'New Project',
        'code' => 'NEW-1',
        'glyph' => 'alt-0',
        'rate_rappen' => 12000,
        'budget_hours' => 100,
        'budget_amount_rappen' => 1200000,
        'billable' => true,
    ])->assertRedirect('/projects/NEW-1');

    expect(Project::where('code', 'NEW-1')->exists())->toBeTrue();
});

test('POST /projects rejects a duplicate code', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->create(['code' => 'DUP', 'client_id' => $client->id]);

    $this->actingAs($user)->post('/projects', [
        'client_id' => $client->id,
        'name' => 'x', 'code' => 'DUP',
        'glyph' => 'alt-0', 'rate_rappen' => 0,
        'billable' => true,
    ])->assertSessionHasErrors('code');
});

test('POST /projects/{p}/archive archives the project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['status' => 'active']);

    $this->actingAs($user)->post("/projects/{$project->code}/archive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe('archived');
});

test('unauthenticated /projects redirects to /login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
