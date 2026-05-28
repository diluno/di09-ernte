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
