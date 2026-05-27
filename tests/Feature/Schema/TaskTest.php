<?php

use App\Models\Task;

test('task belongs to a project', function () {
    $t = Task::factory()->create();
    expect($t->project)->not->toBeNull();
});

test('done state', function () {
    $t = Task::factory()->done()->create();
    expect($t->done)->toBeTrue();
});
