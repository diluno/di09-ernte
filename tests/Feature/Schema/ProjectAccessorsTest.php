<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Anchor the clock so TIMESTAMPDIFF calculations are stable throughout the test run.
    Carbon::setTestNow(Carbon::parse('2026-05-27 12:00:00'));

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create([
        'budget_hours' => 10,
        'rate_rappen' => 10000,
    ]);
});

afterEach(function () {
    Carbon::setTestNow(null);
});

test('spent_hours sums duration of all time entries', function () {
    // 1h entry
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(2),
        'ended_at'   => Carbon::now()->subHour(),
    ]);
    // 30 min entry
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(4),
        'ended_at'   => Carbon::now()->subHours(3)->subMinutes(30),
    ]);

    expect($this->project->spentHours())->toBe(1.5);
});

test('spent_amount_rappen multiplies spent hours by rate', function () {
    // 1h at 10000 rappen/h = 10000
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(2),
        'ended_at'   => Carbon::now()->subHour(),
    ]);

    expect($this->project->spentAmountRappen())->toBe(10000);
});

test('percent_hours and band: ok / warn / over', function () {
    // Step 1: 8h of 10h budget => 80% => ok
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(10),
        'ended_at'   => Carbon::now()->subHours(2),  // exactly 8h
    ]);
    expect($this->project->percentHours())->toBe(80);
    expect($this->project->band)->toBe('ok');

    // Step 2: add 1h => 9h => 90% => warn
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(12),
        'ended_at'   => Carbon::now()->subHours(11),  // exactly 1h
    ]);
    expect($this->project->percentHours())->toBe(90);
    expect($this->project->band)->toBe('warn');

    // Step 3: add 2h => 11h => 110% => over
    TimeEntry::factory()->create([
        'user_id'    => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => Carbon::now()->subHours(14),
        'ended_at'   => Carbon::now()->subHours(12),  // exactly 2h
    ]);
    expect($this->project->percentHours())->toBe(110);
    expect($this->project->band)->toBe('over');
});

test('band is "ok" when budget_hours is 0', function () {
    $noBudget = Project::factory()->create(['budget_hours' => 0, 'rate_rappen' => 5000]);
    expect($noBudget->percentHours())->toBe(0);
    expect($noBudget->band)->toBe('ok');
});
