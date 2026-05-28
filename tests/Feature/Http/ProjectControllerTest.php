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
        'rate_rappen' => 0,
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

test('GET /projects/{code} renders Projects/Show with overview payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT', 'description' => 'Operator UI',
        'budget_hours' => 220, 'budget_amount_rappen' => 31900_00,
        'rate_rappen' => 14500,
        'started_on' => '2026-03-02', 'deadline_on' => '2026-07-18',
    ]);
    \App\Models\Task::create(['project_id' => $project->id, 'name' => 'Cluster rendering', 'budget_hours' => 16, 'done' => false, 'sort_order' => 0]);
    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'description' => 'work', 'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true,
    ]);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('project.code', 'ATLS-FLT')
            ->where('project.client.name', 'Atlas Robotics')
            ->has('project.spent_hours')
            ->has('project.budget_hours')
            ->has('project.band')
            ->has('tasks', 1, fn (Assert $t) => $t
                ->where('name', 'Cluster rendering')
                ->where('done', false)
                ->where('budget_hours', 16)
                ->has('spent_hours')
                ->etc()
            )
            ->has('recent_entries', 1)
            ->has('heatmap', 60)
            ->has('counts.entries')
            ->has('counts.tasks')
        );
});

test('GET /projects/UNKNOWN 404s', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/projects/UNKNOWN')->assertNotFound();
});

test('PATCH /projects/{p} updates fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Old']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertRedirect();

    expect($project->fresh()->name)->toBe('Renamed');
});

test('Projects/Show payload exposes is_pinned reflecting stored state', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['pinned_at' => now()]);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertInertia(fn (Assert $page) => $page->where('project.is_pinned', true));
});

test('Projects/Show payload exposes is_pinned as false when project is not pinned', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['pinned_at' => null]);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertInertia(fn (Assert $page) => $page->where('project.is_pinned', false));
});

test('archiving a pinned project also clears its pin', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['status' => 'active', 'pinned_at' => now()]);

    $this->actingAs($user)->post("/projects/{$project->code}/archive")
        ->assertRedirect();

    $fresh = $project->fresh();
    expect($fresh->status)->toBe('archived');
    expect($fresh->pinned_at)->toBeNull();
});

test('POST /projects/{p}/pin sets pinned_at', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['pinned_at' => null]);

    $this->actingAs($user)->post("/projects/{$project->code}/pin")
        ->assertRedirect();

    expect($project->fresh()->pinned_at)->not->toBeNull();
});

test('POST /projects/{p}/unpin clears pinned_at', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['pinned_at' => now()]);

    $this->actingAs($user)->post("/projects/{$project->code}/unpin")
        ->assertRedirect();

    expect($project->fresh()->pinned_at)->toBeNull();
});

test('GET /projects/create renders Projects/Create with the active clients list', function () {
    $user = User::factory()->create();
    $active = Client::factory()->create(['name' => 'Atlas Robotics']);
    Client::factory()->create(['name' => 'Archived Co', 'archived_at' => now()]);

    $this->actingAs($user)->get('/projects/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Create')
            ->has('clients', 1, fn (Assert $c) => $c
                ->where('id', $active->id)
                ->where('name', 'Atlas Robotics')
            )
        );
});

test('GET /projects/{code}/edit renders Projects/Edit with project money in CHF and clients', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id, 'code' => 'ATLS-FLT',
        'rate_rappen' => 14500, 'budget_amount_rappen' => 31900_00, 'budget_hours' => 220,
    ]);

    $this->actingAs($user)->get("/projects/{$project->code}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Edit')
            ->where('project.code', 'ATLS-FLT')
            ->where('project.rate', 145)            // CHF, not rappen
            ->where('project.budget_amount', 31900) // CHF, not rappen
            ->where('project.budget_hours', 220)
            ->where('project.client_id', $client->id)
            ->has('clients')
        );
});

test('PATCH /projects/{id} with a changed code redirects to the new show URL and persists', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['code' => 'OLD-1']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['code' => 'NEW-1'])
        ->assertRedirect('/projects/NEW-1');

    expect($project->fresh()->code)->toBe('NEW-1');
});

test('PATCH /projects/{id} saving the same code is allowed (unique ignores self) and redirects to show', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['code' => 'KEEP-1', 'name' => 'Old']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['code' => 'KEEP-1', 'name' => 'New'])
        ->assertRedirect('/projects/KEEP-1');

    expect($project->fresh()->name)->toBe('New');
});

test('POST /projects/{code}/unarchive flips an archived project back to active', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['status' => 'archived']);

    $this->actingAs($user)->post("/projects/{$project->code}/unarchive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe('active');
});

test('POST /tasks adds a task that appears on the project show payload', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $this->actingAs($user)->post('/tasks', [
        'project_id' => $project->id,
        'name' => 'Fresh task',
        'budget_hours' => 4,
    ])->assertRedirect();

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1, fn (Assert $t) => $t
                ->where('name', 'Fresh task')
                ->where('budget_hours', 4)
                ->etc()
            )
        );
});
