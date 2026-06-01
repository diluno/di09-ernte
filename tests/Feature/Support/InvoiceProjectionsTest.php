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
    // paid, but issued last year -> NOT paid_ytd (bucketed by issue date, matching Harvest)
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->subYear()->startOfYear()->addDays(5)->toDateString(),
        'paid_at' => now()->startOfYear()->addDays(10),
        'total_rappen' => 500_00]);
    // paid and issued this year -> paid_ytd, regardless of when payment landed
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->startOfYear()->addDays(5)->toDateString(),
        'paid_at' => now()->subYear()->endOfYear()->subDays(2),
        'total_rappen' => 700_00]);
    // draft -> none
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft', 'total_rappen' => 999_00]);

    $stats = InvoiceProjections::stats();

    expect($stats['outstanding'])->toBe(300.0);   // 100 + 200
    expect($stats['overdue'])->toBe(200.0);
    expect($stats['paid_ytd'])->toBe(700.0);       // the invoice issued this calendar year
});

test('index rows expose number, client, total, hours, status, overdue flag', function () {
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2026-014', 'title' => 'Projekt Aurora', 'due_on' => now()->subDay()->toDateString(), 'total_rappen' => 428_000]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 12.5]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 17.0]);

    $rows = InvoiceProjections::index();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('2026-014');
    expect($row['title'])->toBe('Projekt Aurora');
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

test('index orders by document date (issued_on; drafts by created_at) newest first', function () {
    // Insert so id order differs from date order: 2026 first (id 1), then 2025 (id 2),
    // then a draft with no issue date (id 3, created now).
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2026-001', 'issued_on' => '2026-01-01', 'total_rappen' => 100_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2025-001', 'issued_on' => '2025-01-01', 'total_rappen' => 100_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'number' => '2026-900', 'issued_on' => null, 'total_rappen' => 100_00]);

    $rows = InvoiceProjections::index('all');

    // draft (created now) first, then 2026-issued, then 2025-issued.
    expect($rows->pluck('number')->all())->toBe(['2026-900', '2026-001', '2025-001']);
});

test('monthlyIssued buckets sent into open and paid into paid by issue month, in CHF', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2026-01-10', 'total_rappen' => 24_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-01-20', 'due_on' => '2026-02-19', 'total_rappen' => 5_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-04-03', 'due_on' => '2026-05-03', 'total_rappen' => 13_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);

    expect($chart['months'])->toHaveCount(12);
    expect($chart['months'][0])->toBe(['label' => 'Jan', 'open' => 5000.0, 'paid' => 24000.0]);
    expect($chart['months'][1])->toBe(['label' => 'Feb', 'open' => 0.0, 'paid' => 0.0]);
    expect($chart['months'][3])->toBe(['label' => 'Apr', 'open' => 13000.0, 'paid' => 0.0]);
});

test('monthlyIssued excludes draft and void, and buckets by issued_on not paid_at', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2026-01-15', 'paid_at' => '2026-02-15', 'total_rappen' => 9_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'issued_on' => '2026-03-01', 'total_rappen' => 1_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'void',
        'issued_on' => '2026-03-01', 'total_rappen' => 1_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);

    expect($chart['months'][0]['paid'])->toBe(9000.0);
    expect($chart['months'][2])->toBe(['label' => 'Mar', 'open' => 0.0, 'paid' => 0.0]);
});

test('monthlyIssued respects the requested year and reports min/max year bounds', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2024-06-01', 'total_rappen' => 1_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-06-01', 'due_on' => '2026-07-01', 'total_rappen' => 2_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);
    expect($chart['months'][5])->toBe(['label' => 'Jun', 'open' => 2000.0, 'paid' => 0.0]);
    expect($chart['min_year'])->toBe(2024);
    expect($chart['max_year'])->toBe((int) now()->year);

    $empty = InvoiceProjections::monthlyIssued(2025);
    expect(collect($empty['months'])->sum('open'))->toBe(0.0);
    expect(collect($empty['months'])->sum('paid'))->toBe(0.0);
});

test('monthlyIssued falls min_year back to the requested year when there are no invoices', function () {
    $chart = InvoiceProjections::monthlyIssued(2026);
    expect($chart['min_year'])->toBe(2026);
    expect($chart['max_year'])->toBe((int) now()->year);
});
