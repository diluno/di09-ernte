<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Support\EstimateProjections;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics']);
});

test('index maps rows with client, hours, total and expired flag', function () {
    $estimate = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'number' => 'OF-2026-001',
        'valid_until' => now()->subDay()->toDateString(), 'total_rappen' => 10050,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 5.5]);

    $rows = EstimateProjections::index('all');

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('OF-2026-001');
    expect($row['client']['name'])->toBe('Atlas Robotics');
    expect($row['total'])->toBe(100.5);
    expect($row['hours'])->toBe(5.5);
    expect($row['expired'])->toBeTrue();
});

test('the expired filter narrows to past-valid sent estimates', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'valid_until' => now()->subDay()->toDateString()]);
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'valid_until' => now()->addDay()->toDateString()]);

    expect(EstimateProjections::index('expired'))->toHaveCount(1);
});

test('the accepted filter narrows to accepted estimates', function () {
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id]);
    Estimate::factory()->declined()->create(['client_id' => $this->client->id]);

    expect(EstimateProjections::index('accepted'))->toHaveCount(1);
});

test('search matches number or client name', function () {
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-077']);

    expect(EstimateProjections::index('all', '077'))->toHaveCount(1);
    expect(EstimateProjections::index('all', 'Atlas'))->toHaveCount(1);
    expect(EstimateProjections::index('all', 'nope'))->toHaveCount(0);
});

test('stats returns open total, accepted ytd, and acceptance rate', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'total_rappen' => 50000]);
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id, 'total_rappen' => 80000, 'decided_at' => now()]);
    Estimate::factory()->declined()->create(['client_id' => $this->client->id, 'total_rappen' => 20000, 'decided_at' => now()]);

    $stats = EstimateProjections::stats();

    expect($stats['open'])->toBe(500.0);
    expect($stats['accepted_ytd'])->toBe(800.0);
    expect($stats['acceptance_rate'])->toBe(50); // 1 accepted of 2 decided
    expect($stats['count'])->toBe(3);
});
