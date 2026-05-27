<?php

use App\Models\Project;

test('project belongs to a client', function () {
    $p = Project::factory()->create();
    expect($p->client)->not->toBeNull();
});

test('project code is unique', function () {
    Project::factory()->create(['code' => 'ATLS-FLT']);
    expect(fn () => Project::factory()->create(['code' => 'ATLS-FLT']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('active and archived scopes', function () {
    Project::factory()->count(3)->create();
    Project::factory()->archived()->count(2)->create();
    expect(Project::active()->count())->toBe(3);
    expect(Project::archived()->count())->toBe(2);
});
