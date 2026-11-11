<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\ClientDetail;
use App\Support\ClientProjections;
use App\Support\DashboardProjections;
use App\Support\ProjectDetail;
use App\Support\SidebarProps;
use Illuminate\Support\Carbon;

// started_at stores app-timezone wall-clock. A running timer must be measured
// against the app's clock; the database's UTC clock is off by the UTC offset.
beforeEach(function () {
    $this->previousTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Europe/Zurich']);
    date_default_timezone_set('Europe/Zurich');
    Carbon::setTestNow(Carbon::parse('2026-11-10 14:00:00', 'Europe/Zurich'));

    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'rate_rappen' => 12000, 'budget_hours' => 10]);
    $this->entry = TimeEntry::factory()->running()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'started_at' => now()->subMinutes(45),
        'billable' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    date_default_timezone_set($this->previousTimezone);
});

test('a timer started 45 minutes ago in app time counts as 45 minutes everywhere', function () {
    expect($this->entry->fresh()->started_at->format('Y-m-d H:i:s'))->toBe('2026-11-10 13:15:00');

    expect($this->project->spentHours())->toBe(0.75);

    $detail = ProjectDetail::payload($this->project);
    expect($detail['project']['spent_hours'])->toBe(0.75);
    expect(end($detail['heatmap']))->toBe(0.8);

    $client = ClientDetail::payload($this->client);
    expect($client['stats']['hours_ytd'])->toBe(0.8);
    expect($client['stats']['unbilled_hours'])->toBe(0.8);
    expect($client['projects'][0]['spent_hours'])->toBe(0.75);

    $row = ClientProjections::index()->firstWhere('id', $this->client->id);
    expect($row['hours_ytd'])->toBe(0.8);
    expect(end($row['sparkline']))->toBe(0.8);

    $sidebar = SidebarProps::sidebar($this->user);
    expect($sidebar['today_hours'])->toEqual(0.75);
    expect($sidebar['week_hours'][1])->toBe(0.8); // 2026-11-10 is a Tuesday

    expect(DashboardProjections::projects()->firstWhere('id', $this->project->id)['spent_hours'])->toBe(0.75);
    expect(DashboardProjections::stats($this->user)['week_hours'])->toBe(0.8);
});

test('an entry that somehow starts in the future never counts as negative time', function () {
    $this->entry->update(['started_at' => now()->addMinutes(30)]);

    expect($this->project->spentHours())->toBe(0.0);
});
