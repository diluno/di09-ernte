<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Timer\TimerService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->p1 = Project::factory()->create();
    $this->p2 = Project::factory()->create();
    $this->svc = app(TimerService::class);
});

test('start creates a running entry for the user', function () {
    $entry = $this->svc->start($this->user, $this->p1, null, 'Map mode');

    expect($entry)
        ->user_id->toBe($this->user->id)
        ->project_id->toBe($this->p1->id)
        ->description->toBe('Map mode')
        ->ended_at->toBeNull();

    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('start stops any pre-existing running entry atomically', function () {
    $first = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $second = $this->svc->start($this->user, $this->p2);

    expect($first->fresh())->ended_at->not->toBeNull();
    expect($second->fresh())->ended_at->toBeNull();
    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('stop finalizes the running entry', function () {
    $entry = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $stopped = $this->svc->stop($this->user);

    expect($stopped)->id->toBe($entry->id);
    expect($stopped->ended_at)->not->toBeNull();
    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(0);
});

test('stop returns null when nothing is running', function () {
    expect($this->svc->stop($this->user))->toBeNull();
});

test('switch is start with auto-stop', function () {
    $first = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $second = $this->svc->switch($this->user, $this->p2, null, 'PR review');

    expect($first->fresh()->ended_at)->not->toBeNull();
    expect($second)->project_id->toBe($this->p2->id);
    expect($second->description)->toBe('PR review');
});

test('discard hard-deletes the running entry', function () {
    $this->svc->start($this->user, $this->p1);
    $this->svc->discard($this->user);

    expect(TimeEntry::where('user_id', $this->user->id)->count())->toBe(0);
});

test('billable defaults to the project value', function () {
    $billable = Project::factory()->create(['billable' => true]);
    $non = Project::factory()->create(['billable' => false]);

    expect($this->svc->start($this->user, $billable)->billable)->toBeTrue();
    expect($this->svc->switch($this->user, $non)->billable)->toBeFalse();
});
