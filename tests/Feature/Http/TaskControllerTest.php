<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->actingAs($this->user);
});

test('POST /tasks creates a task on the given project', function () {
    $this->post('/tasks', [
        'project_id' => $this->project->id,
        'name' => 'New task',
        'budget_hours' => 8,
    ])->assertRedirect();

    expect(Task::where('project_id', $this->project->id)->first())
        ->name->toBe('New task')
        ->budget_hours->toBe(8)
        ->done->toBeFalse();
});

test('POST /tasks rejects empty name', function () {
    $this->post('/tasks', ['project_id' => $this->project->id, 'name' => ''])
        ->assertSessionHasErrors('name');
});

test('PATCH /tasks/{id} toggles done', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 't', 'sort_order' => 0, 'done' => false]);

    $this->patch("/tasks/{$task->id}", ['done' => true])->assertRedirect();
    expect($task->fresh()->done)->toBeTrue();

    $this->patch("/tasks/{$task->id}", ['done' => false])->assertRedirect();
    expect($task->fresh()->done)->toBeFalse();
});

test('PATCH /tasks/{id} renames + updates budget', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 'old', 'sort_order' => 0]);

    $this->patch("/tasks/{$task->id}", ['name' => 'new', 'budget_hours' => 12])->assertRedirect();
    $t = $task->fresh();
    expect($t->name)->toBe('new');
    expect($t->budget_hours)->toBe(12);
});

test('PATCH /tasks/reorder updates sort_order for many tasks atomically', function () {
    $a = Task::create(['project_id' => $this->project->id, 'name' => 'A', 'sort_order' => 0]);
    $b = Task::create(['project_id' => $this->project->id, 'name' => 'B', 'sort_order' => 1]);
    $c = Task::create(['project_id' => $this->project->id, 'name' => 'C', 'sort_order' => 2]);

    $this->patch('/tasks/reorder', [
        'order' => [$c->id, $a->id, $b->id],
    ])->assertRedirect();

    expect($a->fresh()->sort_order)->toBe(1);
    expect($b->fresh()->sort_order)->toBe(2);
    expect($c->fresh()->sort_order)->toBe(0);
});

test('DELETE /tasks/{id} deletes the task', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 'gone', 'sort_order' => 0]);

    $this->delete("/tasks/{$task->id}")->assertRedirect();
    expect(Task::find($task->id))->toBeNull();
});
