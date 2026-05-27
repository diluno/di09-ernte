<?php

use App\Models\Client;
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
