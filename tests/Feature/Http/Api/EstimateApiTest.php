<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/estimates requires authentication', function () {
    $this->getJson('/api/estimates')->assertUnauthorized();
});

test('GET /api/estimates returns a paginated list and stats', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $est = Estimate::factory()->create(['client_id' => $client->id, 'number' => 'OF-2026-001', 'status' => 'sent']);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'hours' => 3.0]);

    $this->getJson('/api/estimates')
        ->assertOk()
        ->assertJsonStructure([
            'estimates' => [
                'current_page', 'last_page', 'total',
                'data' => [['id', 'number', 'title', 'status', 'expired', 'total', 'hours', 'client' => ['id', 'name']]],
            ],
            'stats',
        ])
        ->assertJsonPath('estimates.data.0.number', 'OF-2026-001')
        ->assertJsonPath('estimates.total', 1);
});

test('GET /api/estimates?filter=accepted narrows to accepted estimates', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create();
    Estimate::factory()->create(['client_id' => $client->id, 'status' => 'accepted']);
    Estimate::factory()->create(['client_id' => $client->id, 'status' => 'sent']);

    $this->getJson('/api/estimates?filter=accepted')
        ->assertOk()
        ->assertJsonPath('estimates.total', 1);
});

test('GET /api/estimates/{number} returns the detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $est = Estimate::factory()->create([
        'client_id' => $client->id, 'number' => 'OF-2026-007', 'title' => 'Proposal', 'status' => 'sent',
        'subtotal_rappen' => 50000, 'vat_rappen' => 4050, 'total_rappen' => 54050,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'description' => 'Scope', 'hours' => 4, 'rate_rappen' => 12500, 'amount_rappen' => 50000]);

    $this->getJson('/api/estimates/OF-2026-007')
        ->assertOk()
        ->assertJsonStructure([
            'id', 'number', 'status', 'expired', 'title', 'client' => ['id', 'name'],
            'issued_on', 'valid_until', 'subtotal', 'vat', 'total', 'vat_rate', 'notes',
            'lines' => [['id', 'description', 'hours', 'rate', 'amount']],
            'converted_invoice',
        ])
        ->assertJsonPath('number', 'OF-2026-007')
        ->assertJsonPath('total', 540.5)
        ->assertJsonPath('lines.0.description', 'Scope');
});

test('GET /api/estimates/{number} returns 404 for an unknown number', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/estimates/OF-9999-999')->assertNotFound();
});

test('GET /api/estimates/{number} requires authentication', function () {
    $est = Estimate::factory()->create(['number' => 'OF-2026-009']);
    $this->getJson("/api/estimates/{$est->number}")->assertUnauthorized();
});
