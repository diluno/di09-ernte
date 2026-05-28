<?php

use App\Models\Client;
use App\Models\Project;
use App\Services\Harvest\ProjectImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client]; // harvest client id 77 => ernte client
});

test('maps fields, links the client, converts rate to rappen', function () {
    $projects = [[
        'id' => 1, 'client' => ['id' => 77], 'name' => 'Online Store', 'code' => 'OS1',
        'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0,
        'budget' => 200.0, 'budget_by' => 'project', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30',
    ]];

    $map = (new ProjectImporter())->import($projects, $this->clientMap);

    $p = $map[1];
    expect($p)->toBeInstanceOf(Project::class);
    expect($p->client_id)->toBe($this->client->id);
    expect($p->name)->toBe('Online Store');
    expect($p->code)->toBe('OS1');
    expect($p->status)->toBe('active');
    expect($p->billable)->toBeTrue();
    expect($p->rate_rappen)->toBe(14500);
    expect($p->budget_hours)->toBe(200);
    expect($p->budget_amount_rappen)->toBe(0);
    expect($p->started_on->toDateString())->toBe('2026-01-01');
});

test('amount-based budget maps to budget_amount_rappen', function () {
    $projects = [[
        'id' => 2, 'client' => ['id' => 77], 'name' => 'Retainer', 'code' => '',
        'is_active' => false, 'is_billable' => false, 'hourly_rate' => null,
        'budget' => 5000.0, 'budget_by' => 'project_cost',
    ]];

    $p = (new ProjectImporter())->import($projects, $this->clientMap)[2];

    expect($p->status)->toBe('archived');
    expect($p->rate_rappen)->toBe(0);
    expect($p->budget_amount_rappen)->toBe(500000);
    expect($p->budget_hours)->toBe(0);
    expect($p->code)->not->toBe(''); // generated from name when blank
});

test('skips projects whose client was not imported', function () {
    $map = (new ProjectImporter())->import([
        ['id' => 99, 'client' => ['id' => 12345], 'name' => 'Orphan', 'code' => 'ORP', 'is_active' => true],
    ], $this->clientMap); // clientMap only contains harvest client 77

    expect($map)->toBeEmpty();
    expect(Project::count())->toBe(0);
});
