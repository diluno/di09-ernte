<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('unauthenticated search redirects to login', function () {
    $this->get('/api/search?q=atlas')->assertRedirect('/login');
});

test('empty search returns at most eight mixed defaults', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    Project::factory()->count(5)->create(['client_id' => $client->id]);
    Invoice::factory()->count(5)->create(['client_id' => $client->id]);

    $results = $this->actingAs($this->user)->getJson('/api/search')->assertOk()->json();

    expect($results)->toHaveCount(8);
    expect(collect($results)->pluck('type')->unique()->values()->all())->toContain('project', 'client', 'invoice');
});

test('search finds projects by name, code, and client name', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Fleet Dashboard',
        'code' => 'ATLS-FLT',
    ]);

    $byName = collect($this->actingAs($this->user)->getJson('/api/search?q=Fleet')->json());
    $byCode = collect($this->actingAs($this->user)->getJson('/api/search?q=ATLS')->json());
    $byClient = collect($this->actingAs($this->user)->getJson('/api/search?q=Atlas')->json());

    foreach ([$byName, $byCode, $byClient] as $results) {
        $row = $results->firstWhere('type', 'project');
        expect($row['id'])->toBe($project->id);
        expect($row['url'])->toBe('/projects/ATLS-FLT');
    }
});

test('search finds clients by name contact and email', function () {
    $client = Client::factory()->create([
        'name' => 'Northlit Press',
        'contact_name' => 'Annie Park',
        'email' => 'annie@northlit.test',
    ]);

    foreach (['Northlit', 'Annie', 'northlit.test'] as $term) {
        $results = collect($this->actingAs($this->user)->getJson("/api/search?q={$term}")->json());
        $row = $results->firstWhere('type', 'client');
        expect($row['id'])->toBe($client->id);
        expect($row['url'])->toBe("/clients/{$client->id}/edit");
    }
});

test('search finds invoices by number and client name', function () {
    $client = Client::factory()->create(['name' => 'Kelp Forest Co.']);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => '2026-042',
        'status' => 'sent',
    ]);

    $byNumber = collect($this->actingAs($this->user)->getJson('/api/search?q=2026-042')->json());
    $byClient = collect($this->actingAs($this->user)->getJson('/api/search?q=Kelp')->json());

    foreach ([$byNumber, $byClient] as $results) {
        $row = $results->firstWhere('type', 'invoice');
        expect($row['id'])->toBe($invoice->id);
        expect($row['url'])->toBe('/invoices/2026-042');
    }
});

test('type filter narrows search results', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    Project::factory()->create(['client_id' => $client->id, 'name' => 'Atlas Project']);
    Invoice::factory()->create(['client_id' => $client->id, 'number' => '2026-777']);

    $results = $this->actingAs($this->user)->getJson('/api/search?q=Atlas&type=project')->assertOk()->json();

    expect($results)->not->toBeEmpty();
    expect(collect($results)->pluck('type')->unique()->all())->toBe(['project']);
});
