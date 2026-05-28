<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

test('GET /invoices renders Invoices/Index with rows + stats + counts', function () {
    // Use non-round rappen/hours so JSON serialisation preserves float type
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-001',
        'status' => 'sent', 'due_on' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_50]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 5.5]);

    $this->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->has('invoices', 1, fn (Assert $r) => $r
                ->where('number', '2026-001')
                ->where('client.name', 'Atlas Robotics')
                ->where('total', 100.5)
                ->where('hours', 5.5)
                ->where('status', 'sent')
                ->where('overdue', false)
                ->etc())
            ->has('stats', fn (Assert $s) => $s
                ->where('outstanding', 100.5)
                ->has('overdue')->has('paid_ytd')->has('avg_days_to_pay')->etc())
            ->has('counts', fn (Assert $c) => $c
                ->where('all', 1)->has('draft')->has('sent')->has('overdue')->has('paid')->etc())
            ->where('filters.filter', 'all'));
});

test('GET /invoices?filter=overdue narrows to past-due sent invoices', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->subDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);

    $this->get('/invoices?filter=overdue')
        ->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
});

test('unauthenticated /invoices redirects to login', function () {
    auth()->logout();
    $this->get('/invoices')->assertRedirect('/login');
});

test('GET /invoices/new defaults to previous month and lists billable unbilled entries', function () {
    $prevMonth = now()->subMonthNoOverflow();
    // 2.5h (non-round) so JSON serialisation preserves the float type (see Index test)
    $inRange = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'In range',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(9, 0),
        'ended_at'   => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(11, 30),
        'billable' => true,
    ]);
    // out of range (this month) — excluded by default period
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'This month',
        'started_at' => now()->startOfMonth()->addDay(), 'ended_at' => now()->startOfMonth()->addDay()->addHour(),
        'billable' => true,
    ]);
    // non-billable — excluded
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Internal',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(6), 'ended_at' => $prevMonth->copy()->startOfMonth()->addDays(6)->addHour(),
        'billable' => false,
    ]);

    $this->get("/invoices/new?client={$this->client->id}&project={$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('client.id', $this->client->id)
            ->where('project.id', $this->project->id)
            ->has('period.start')->has('period.end')
            ->has('entries', 1, fn (Assert $e) => $e->where('description', 'In range')->etc())
            ->has('suggested_lines', 1, fn (Assert $l) => $l->where('description', 'In range')->where('hours', 2.5)->etc()));
});

test('POST /invoices creates a draft from submitted lines and redirects to its detail', function () {
    $entry = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Work',
        'started_at' => now()->subDays(20), 'ended_at' => now()->subDays(20)->addHours(2), 'billable' => true,
    ]);

    $res = $this->post('/invoices', [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [$entry->id],
        'lines' => [
            ['description' => 'Work', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
        ],
    ]);

    $invoice = Invoice::latest('id')->first();
    $res->assertRedirect("/invoices/{$invoice->number}");
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->total_rappen)->toBe(31349); // 29000 + 8.10%
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
});

test('POST /invoices requires at least one line', function () {
    $this->post('/invoices', [
        'client_id' => $this->client->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [],
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});
