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
        'client_id' => $this->client->id, 'number' => 'OF-2026-001', 'title' => 'Augenhöhe',
        'valid_until' => now()->subDay()->toDateString(), 'total_rappen' => 10050,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 5.5]);

    $rows = EstimateProjections::index('all');

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('OF-2026-001');
    expect($row['title'])->toBe('Augenhöhe');
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

test('index orders by document date (issued_on; drafts by created_at) newest first', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id,
        'number' => 'OF-2026-001', 'issued_on' => '2026-01-01', 'total_rappen' => 100_00]);
    Estimate::factory()->sent()->create(['client_id' => $this->client->id,
        'number' => 'OF-2025-001', 'issued_on' => '2025-01-01', 'total_rappen' => 100_00]);
    Estimate::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'number' => 'OF-2026-900', 'issued_on' => null, 'total_rappen' => 100_00]);

    $rows = EstimateProjections::index('all');

    expect($rows->pluck('number')->all())->toBe(['OF-2026-900', 'OF-2026-001', 'OF-2025-001']);
});

test('stats adds counts, expired and draft totals for the list strip', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'total_rappen' => 50000]);
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'total_rappen' => 12345,
        'valid_until' => now()->subDays(2)->toDateString()]);
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id, 'total_rappen' => 80000, 'decided_at' => now()]);
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id, 'total_rappen' => 1000, 'decided_at' => now()->subYear()]);
    Estimate::factory()->declined()->create(['client_id' => $this->client->id, 'total_rappen' => 20000, 'decided_at' => now()]);
    Estimate::factory()->create(['client_id' => $this->client->id, 'status' => 'draft', 'total_rappen' => 30050]);

    $stats = EstimateProjections::stats();

    expect($stats['open'])->toBe(623.45);
    expect($stats['open_count'])->toBe(2);
    expect($stats['expired'])->toBe(123.45);
    expect($stats['expired_count'])->toBe(1);
    expect($stats['accepted_ytd'])->toBe(800.0);
    expect($stats['accepted_ytd_count'])->toBe(1);
    expect($stats['accepted_count'])->toBe(2);
    expect($stats['decided_count'])->toBe(3);
    expect($stats['acceptance_rate'])->toBe(67);
    expect($stats['drafts'])->toBe(300.5);
    expect($stats['drafts_count'])->toBe(1);
});

test('stats are zero and null-rate on an empty table', function () {
    $stats = EstimateProjections::stats();

    expect($stats['expired'])->toBe(0.0);
    expect($stats['expired_count'])->toBe(0);
    expect($stats['drafts'])->toBe(0.0);
    expect($stats['acceptance_rate'])->toBeNull();
});

test('detail exposes validity days, send channel and the weighted rate', function () {
    $estimate = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'valid_until' => now()->addDays(5)->toDateString(),
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 1, 'rate_rappen' => 10000]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 3, 'rate_rappen' => 20000]);
    $estimate->events()->create(['kind' => 'sent', 'occurred_at' => now(), 'payload' => ['manual' => true]]);

    $detail = EstimateProjections::detail($estimate->fresh());

    expect($detail['days_left'])->toBe(5);
    expect($detail['days_expired'])->toBeNull();
    expect($detail['issued_channel'])->toBe('manual');
    expect($detail['avg_rate'])->toBe(175.0);
});

test('detail counts the days since an estimate expired', function () {
    $estimate = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'valid_until' => now()->subDays(4)->toDateString(),
    ]);
    $estimate->events()->create(['kind' => 'sent', 'occurred_at' => now()->subDays(34), 'payload' => ['email_to' => ['a@b.test']]]);

    $detail = EstimateProjections::detail($estimate->fresh());

    expect($detail['expired'])->toBeTrue();
    expect($detail['days_left'])->toBeNull();
    expect($detail['days_expired'])->toBe(4);
    expect($detail['issued_channel'])->toBe('email');
});

test('detail leaves validity days and channel empty for a draft', function () {
    $estimate = Estimate::factory()->create(['client_id' => $this->client->id, 'status' => 'draft']);

    $detail = EstimateProjections::detail($estimate);

    expect($detail['days_left'])->toBeNull();
    expect($detail['days_expired'])->toBeNull();
    expect($detail['issued_channel'])->toBeNull();
    expect($detail['avg_rate'])->toBeNull();
});
