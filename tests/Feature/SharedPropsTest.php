<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared props expose running_entry as null when nothing is running', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('running_entry', null));
});

test('shared props expose the running entry with project + task labels', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT']);

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'description' => 'Telemetry side-panel',
        'started_at' => now()->subMinutes(15),
        'ended_at' => null,
        'billable' => true,
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('running_entry', fn (Assert $e) => $e
                ->where('project.name', 'Fleet Console v2')
                ->where('project.code', 'ATLS-FLT')
                ->where('description', 'Telemetry side-panel')
                ->has('started_at')
                ->has('id')
                ->etc()
            )
        );
});

test('sidebar shared prop contains nav_counts, pinned, week_hours', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->count(3)->create(['client_id' => $client->id, 'status' => 'active']);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sidebar', fn (Assert $s) => $s
                ->has('nav_counts.projects')
                ->has('nav_counts.clients')
                ->has('pinned')                  // array of {code, name, glyph}
                ->has('week_hours')              // 7-element array Mon..Sun
                ->has('today_hours')             // number, seconds today
                ->etc()
            )
        );
});
