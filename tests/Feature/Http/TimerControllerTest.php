<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
    $this->actingAs($this->user);
});

test('GET /timer renders Timer/Today with today payload', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'morning task',
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
        'billable' => true,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('entries', 1)
            ->has('totals', fn (Assert $t) => $t
                ->has('total_seconds')
                ->has('billable_seconds')
                ->has('earnings_amount')
            )
            ->has('by_project', 1)
            ->has('quick_start', fn (Assert $q) => $q->etc())
        );
});

test('GET /timer defaults to today and flags is_today', function () {
    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->where('date', today()->toDateString())
            ->where('is_today', true)
            ->etc()
        );
});

test('GET /timer?date= shows only that day\'s entries and flags is_today false', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'three days ago',
        'started_at' => today()->subDays(3)->setHour(9), 'ended_at' => today()->subDays(3)->setHour(11),
        'billable' => true,
    ]);
    // A today entry that must NOT leak into the past-day view.
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'today',
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
        'billable' => true,
    ]);

    $this->get('/timer?date='.today()->subDays(3)->toDateString())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->where('date', today()->subDays(3)->toDateString())
            ->where('is_today', false)
            ->has('entries', 1)
            ->where('entries.0.description', 'three days ago')
            ->where('totals.total_seconds', 7200)
            ->etc()
        );
});

test('GET /timer with a malformed date falls back to today', function () {
    $this->get('/timer?date=not-a-date')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->where('date', today()->toDateString())
            ->where('is_today', true)
            ->etc()
        );
});

test('today entries carry the project name even when description is blank, so the row stays identifiable', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => '',
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
        'billable' => true,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('entries.0', fn (Assert $e) => $e
                ->where('description', '')
                ->where('project.name', $this->project->name)
                ->etc()
            )
        );
});

test('today payload flags the running entry so the row can hide edit/delete controls', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'finished', 'billable' => true,
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
    ]);
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'in progress', 'billable' => true,
        'started_at' => today()->setHour(11), 'ended_at' => null,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('entries', 2)
            ->where('entries.0.running', false)
            ->where('entries.1.running', true)
            ->etc()
        );
});

test('GET /timer exposes all active projects for manual entry, including budget-less ones beyond the recent 4', function () {
    Project::factory()->count(4)->create(); // pushes the others out of the recent-4 quick_start
    Project::factory()->create([
        'name' => 'Internal Ops', 'code' => 'INTOPS',
        'budget_hours' => 0, 'budget_amount_rappen' => 0, 'rate_rappen' => 0,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('projects', 6) // 1 (beforeEach) + 4 + the budget-less one
            ->where('projects', fn ($projects) => collect($projects)->contains(fn ($p) => $p['code'] === 'INTOPS'))
            ->etc()
        );
});

test('POST /timer/start creates a running entry', function () {
    $this->post('/timer/start', [
        'project_id' => $this->project->id,
        'description' => 'kick off',
    ])->assertRedirect();

    expect(TimeEntry::running()->count())->toBe(1);
    $e = TimeEntry::running()->first();
    expect($e->description)->toBe('kick off');
    expect($e->project_id)->toBe($this->project->id);
});

test('POST /timer/start auto-stops the previous running entry', function () {
    $other = Project::factory()->create();

    $this->post('/timer/start', ['project_id' => $other->id]);
    $first = TimeEntry::running()->first();
    expect($first)->not->toBeNull();

    $this->post('/timer/start', ['project_id' => $this->project->id]);

    expect(TimeEntry::running()->count())->toBe(1);
    expect($first->fresh()->ended_at)->not->toBeNull();
});

test('POST /timer/stop ends the running entry', function () {
    $this->post('/timer/start', ['project_id' => $this->project->id]);
    expect(TimeEntry::running()->count())->toBe(1);

    $this->post('/timer/stop')->assertRedirect();
    expect(TimeEntry::running()->count())->toBe(0);
});

test('POST /timer/switch behaves like start', function () {
    $other = Project::factory()->create();
    $this->post('/timer/start', ['project_id' => $other->id]);

    $this->post('/timer/switch', ['project_id' => $this->project->id, 'description' => 'new context'])
        ->assertRedirect();

    expect(TimeEntry::running()->first()->project_id)->toBe($this->project->id);
});

test('POST /timer/discard removes the running entry without keeping a row', function () {
    $this->post('/timer/start', ['project_id' => $this->project->id]);
    $id = TimeEntry::running()->first()->id;

    $this->post('/timer/discard')->assertRedirect();
    expect(TimeEntry::find($id))->toBeNull();
});

test('POST /timer/start with task_id requires the task to belong to the project', function () {
    $otherProject = Project::factory()->create();
    $task = Task::create(['project_id' => $otherProject->id, 'name' => 'x', 'sort_order' => 0]);

    $this->post('/timer/start', [
        'project_id' => $this->project->id,
        'task_id' => $task->id,
    ])->assertSessionHasErrors('task_id');
});
