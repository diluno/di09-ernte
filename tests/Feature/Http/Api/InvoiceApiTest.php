<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/invoices requires authentication', function () {
    $this->getJson('/api/invoices')->assertUnauthorized();
});

test('GET /api/invoices returns a paginated list and stats', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $inv = Invoice::factory()->sent()->create(['client_id' => $client->id, 'number' => '2026-001']);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 5.5]);

    $this->getJson('/api/invoices')
        ->assertOk()
        ->assertJsonStructure([
            'invoices' => [
                'current_page', 'last_page', 'total',
                'data' => [['id', 'number', 'title', 'status', 'overdue', 'total', 'hours', 'client' => ['id', 'name']]],
            ],
            'stats' => ['outstanding', 'overdue', 'paid_ytd', 'count'],
        ])
        ->assertJsonPath('invoices.data.0.number', '2026-001')
        ->assertJsonPath('invoices.total', 1);
});

test('GET /api/invoices?filter=overdue narrows to overdue sent invoices', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create();
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->subDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);

    $this->getJson('/api/invoices?filter=overdue')
        ->assertOk()
        ->assertJsonPath('invoices.total', 1);
});

test('GET /api/invoices/{number} returns the detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $inv = Invoice::factory()->sent()->create([
        'client_id' => $client->id, 'number' => '2026-007', 'title' => 'March work',
        'subtotal_rappen' => 100000, 'vat_rappen' => 8100, 'total_rappen' => 108100,
    ]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'description' => 'Dev', 'hours' => 5, 'rate_rappen' => 14500, 'amount_rappen' => 72500]);

    $this->getJson('/api/invoices/2026-007')
        ->assertOk()
        ->assertJsonStructure([
            'id', 'number', 'status', 'overdue', 'title', 'client' => ['id', 'name'],
            'issued_on', 'due_on', 'subtotal', 'vat', 'total', 'vat_rate', 'notes',
            'lines' => [['id', 'description', 'hours', 'rate', 'amount']],
        ])
        ->assertJsonPath('number', '2026-007')
        ->assertJsonPath('total', 1081.0)
        ->assertJsonPath('lines.0.description', 'Dev');
});

test('GET /api/invoices/{number} returns 404 for an unknown number', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/invoices/9999-999')->assertNotFound();
});

test('GET /api/invoices/{number} requires authentication', function () {
    $inv = Invoice::factory()->create(['number' => '2026-009']);
    $this->getJson("/api/invoices/{$inv->number}")->assertUnauthorized();
});
