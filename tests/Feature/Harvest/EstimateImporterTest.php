<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Services\Harvest\EstimateImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client];
});

function harvestEstimate(array $overrides = []): array
{
    return array_merge([
        'id' => 5001, 'client' => ['id' => 77], 'number' => 'EST-1001',
        'state' => 'accepted', 'currency' => 'CHF', 'issue_date' => '2025-02-01',
        'sent_at' => '2025-02-01T09:00:00Z', 'accepted_at' => '2025-02-05T10:00:00Z', 'declined_at' => null,
        'tax' => 8.1, 'tax_amount' => 8.1, 'tax2' => null, 'tax2_amount' => null,
        'amount' => 108.1, 'subject' => 'Partnerschaft auf Augenhöhe', 'notes' => null,
        'line_items' => [
            ['id' => 1, 'description' => 'Phase 1', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'taxed' => true],
        ],
    ], $overrides);
}

test('preserves number, maps state 1:1, stamps decided_at, copies totals + lines', function () {
    $result = (new EstimateImporter())->import([harvestEstimate()], $this->clientMap);

    expect($result['imported'])->toBe(1);
    $est = Estimate::first();
    expect($est->number)->toBe('EST-1001');
    expect($est->title)->toBe('Partnerschaft auf Augenhöhe');
    expect($est->status)->toBe('accepted');
    expect($est->issued_on->toDateString())->toBe('2025-02-01');
    expect($est->valid_until)->toBeNull();
    expect($est->decided_at)->not->toBeNull();
    expect($est->total_rappen)->toBe(10810);
    expect($est->vat_rappen)->toBe(810);
    expect($est->subtotal_rappen)->toBe(10000);
    expect($est->lines)->toHaveCount(1);
    expect($est->events()->where('kind', 'created')->count())->toBe(1);
});

test('declined estimate stamps decided_at from declined_at', function () {
    (new EstimateImporter())->import([harvestEstimate([
        'id' => 2, 'number' => 'EST-2', 'state' => 'declined',
        'accepted_at' => null, 'declined_at' => '2025-02-06T10:00:00Z',
    ])], $this->clientMap);

    $est = Estimate::where('number', 'EST-2')->first();
    expect($est->status)->toBe('declined');
    expect($est->decided_at)->not->toBeNull();
});

test('draft estimate has no decided_at', function () {
    (new EstimateImporter())->import([harvestEstimate([
        'id' => 3, 'number' => 'EST-3', 'state' => 'draft', 'accepted_at' => null, 'declined_at' => null,
    ])], $this->clientMap);

    expect(Estimate::where('number', 'EST-3')->value('decided_at'))->toBeNull();
});

test('skips an estimate whose client was not imported', function () {
    $result = (new EstimateImporter())->import([harvestEstimate(['client' => ['id' => 99999]])], $this->clientMap);

    expect($result['imported'])->toBe(0);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Estimate::count())->toBe(0);
});

test('skips an estimate with negative amounts', function () {
    $result = (new EstimateImporter())->import([harvestEstimate([
        'number' => 'CN-2', 'amount' => -50.0,
        'line_items' => [['id' => 1, 'description' => 'Credit', 'quantity' => 1.0, 'unit_price' => -50.0, 'amount' => -50.0, 'taxed' => true]],
    ])], $this->clientMap);

    expect($result['imported'])->toBe(0);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Estimate::count())->toBe(0);
});
