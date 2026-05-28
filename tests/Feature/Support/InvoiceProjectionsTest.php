<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\ClientProjections;
use App\Support\DashboardProjections;
use App\Support\InvoiceProjections;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
});

test('stats sum outstanding (sent), overdue, and paid this calendar year', function () {
    // sent, not yet due -> outstanding only
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_00]);
    // sent, past due -> outstanding AND overdue
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->subDays(3)->toDateString(), 'total_rappen' => 200_00]);
    // paid this year -> paid_ytd, even if issued earlier
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->subYear()->startOfYear()->addDays(5)->toDateString(),
        'paid_at' => now()->startOfYear()->addDays(10),
        'total_rappen' => 500_00]);
    // issued this year but paid last year -> not paid_ytd
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->startOfYear()->addDays(5)->toDateString(),
        'paid_at' => now()->subYear()->endOfYear()->subDays(2),
        'total_rappen' => 700_00]);
    // draft -> none
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft', 'total_rappen' => 999_00]);

    $stats = InvoiceProjections::stats();

    expect($stats['outstanding'])->toBe(300.0);   // 100 + 200
    expect($stats['overdue'])->toBe(200.0);
    expect($stats['paid_ytd'])->toBe(500.0);
});

test('index rows expose number, client, total, hours, status, overdue flag', function () {
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2026-014', 'due_on' => now()->subDay()->toDateString(), 'total_rappen' => 428_000]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 12.5]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 17.0]);

    $rows = InvoiceProjections::index();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('2026-014');
    expect($row['client']['name'])->toBe('Atlas Robotics');
    expect($row['total'])->toBe(4280.0);
    expect($row['hours'])->toBe(29.5);
    expect($row['status'])->toBe('sent');
    expect($row['overdue'])->toBeTrue();
});

test('index filter narrows by status; overdue is a virtual filter over sent', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->subDay()->toDateString()]);

    expect(InvoiceProjections::index('draft'))->toHaveCount(1);
    expect(InvoiceProjections::index('overdue'))->toHaveCount(1);
    expect(InvoiceProjections::index('sent'))->toHaveCount(1);
});

test('per-client outstanding lands on ClientProjections rows', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'total_rappen' => 150_00]);

    $row = ClientProjections::index()->firstWhere('id', $this->client->id);
    expect($row['outstanding'])->toBe(150.0);
});

test('global outstanding_amount lands on DashboardProjections stats', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'total_rappen' => 321_00]);

    $stats = DashboardProjections::stats(User::factory()->create());
    expect($stats['outstanding_amount'])->toBe(321.0);
});
