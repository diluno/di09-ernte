<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('GET /clients renders Clients/Index with projection rows', function () {
    $c = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR', 'default_rate_rappen' => 14500]);
    $p = Project::factory()->create(['client_id' => $c->id, 'rate_rappen' => 14500]);
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $p->id,
        'description' => 'work', 'started_at' => now()->startOfYear()->addDays(10),
        'ended_at' => now()->startOfYear()->addDays(10)->addHours(2),
        'billable' => true,
    ]);

    $this->get('/clients')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')
            ->has('clients', 1, fn (Assert $row) => $row
                ->where('name', 'Atlas Robotics')
                ->where('short_code', 'AR')
                ->where('default_rate', 145)
                ->where('projects_count', 1)
                ->has('hours_ytd')
                ->has('sparkline', 14)
                ->etc()
            )
        );
});

test('GET /clients/{id} renders Clients/Show with projects invoices and recent activity', function () {
    $c = Client::factory()->create([
        'name' => 'Atlas Robotics',
        'short_code' => 'AR',
        'contact_name' => 'Marit Hesse',
        'email' => 'marit@atlas.test',
        'default_rate_rappen' => 14500,
    ]);
    $p = Project::factory()->create([
        'client_id' => $c->id,
        'name' => 'Fleet Console',
        'code' => 'ATLS-FLT',
        'rate_rappen' => 14500,
        'budget_hours' => 20,
    ]);
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $p->id,
        'description' => 'Interface pass',
        'started_at' => now()->subDays(2),
        'ended_at' => now()->subDays(2)->addHours(2),
        'billable' => true,
    ]);
    $invoice = Invoice::factory()->create([
        'client_id' => $c->id,
        'project_id' => $p->id,
        'number' => '2026-101',
        'status' => 'sent',
        'total_rappen' => 31349,
    ]);
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'hours' => 2.0]);

    $this->get("/clients/{$c->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Show')
            ->where('client.id', $c->id)
            ->where('client.name', 'Atlas Robotics')
            ->where('client.default_rate', 145)
            ->has('stats', fn (Assert $stats) => $stats
                ->where('projects', 1)
                ->where('active_projects', 1)
                ->where('hours_ytd', 2)
                ->where('outstanding', 313.49)
                ->etc()
            )
            ->has('projects', 1, fn (Assert $project) => $project
                ->where('code', 'ATLS-FLT')
                ->where('name', 'Fleet Console')
                ->where('spent_hours', 2)
                ->etc()
            )
            ->has('recent_entries', 1, fn (Assert $recent) => $recent
                ->where('id', $entry->id)
                ->where('description', 'Interface pass')
                ->where('project.code', 'ATLS-FLT')
                ->etc()
            )
            ->has('invoices', 1, fn (Assert $row) => $row
                ->where('number', '2026-101')
                ->where('total', 313.49)
                ->where('url', '/invoices/2026-101')
                ->etc()
            )
        );
});

test('GET /clients/create renders Clients/Create', function () {
    $this->get('/clients/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Clients/Create'));
});

test('POST /clients creates a client', function () {
    $this->post('/clients', [
        'name' => 'New Co', 'short_code' => 'NC', 'country' => 'CH',
    ])->assertRedirect('/clients');
    expect(Client::where('name', 'New Co')->exists())->toBeTrue();
});

test('POST /clients rejects duplicate short_code', function () {
    Client::factory()->create(['short_code' => 'DUP']);
    $this->post('/clients', ['name' => 'X', 'short_code' => 'DUP', 'country' => 'CH'])
        ->assertSessionHasErrors('short_code');
});

test('GET /clients/{id}/edit renders Clients/Edit', function () {
    $c = Client::factory()->create();
    $this->get("/clients/{$c->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Edit')
            ->where('client.id', $c->id)
        );
});

test('PATCH /clients/{id} updates the client', function () {
    $c = Client::factory()->create(['name' => 'Old']);
    $this->patch("/clients/{$c->id}", ['name' => 'Renamed'])->assertRedirect();
    expect($c->fresh()->name)->toBe('Renamed');
});

test('DELETE /clients/{id} archives instead of deleting', function () {
    $c = Client::factory()->create();
    $this->delete("/clients/{$c->id}")->assertRedirect();
    expect($c->fresh()->archived_at)->not->toBeNull();
});
