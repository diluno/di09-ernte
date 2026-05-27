<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

test('a user can have at most one running time entry', function () {
    $user = User::factory()->create();
    $p1 = Project::factory()->create();
    $p2 = Project::factory()->create();

    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $p1->id,
        'started_at' => now()->subMinutes(30),
        'ended_at' => null,
    ]);

    expect(fn () => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $p2->id,
        'started_at' => now(),
        'ended_at' => null,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('a user can have many finished time entries', function () {
    $user = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->count(5)->create([
        'user_id' => $user->id,
        'project_id' => $p->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHour(),
    ]);

    expect(TimeEntry::where('user_id', $user->id)->count())->toBe(5);
});

test('different users can each have a running timer', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->create(['user_id' => $u1->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);
    TimeEntry::factory()->create(['user_id' => $u2->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);

    expect(TimeEntry::whereNull('ended_at')->count())->toBe(2);
});

test('TimeEntry::running scope returns only running entries', function () {
    $user = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $p->id, 'started_at' => now()->subHour(), 'ended_at' => now()]);
    $running = TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);

    expect(TimeEntry::running()->pluck('id')->all())->toBe([$running->id]);
});
