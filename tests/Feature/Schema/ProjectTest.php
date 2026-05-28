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

test('a project can be pinned via pinned_at and the pinned scope finds it', function () {
    $pinned = Project::factory()->create(['pinned_at' => now()]);
    $unpinned = Project::factory()->create(['pinned_at' => null]);

    expect($pinned->pinned_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);

    $ids = Project::pinned()->pluck('id');
    expect($ids)->toContain($pinned->id);
    expect($ids)->not->toContain($unpinned->id);
});
