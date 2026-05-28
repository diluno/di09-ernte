<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeHarvest(): void
{
    Http::fake([
        '*/clients*' => Http::response(['clients' => [['id' => 77, 'name' => 'Atlas Robotics', 'is_active' => true]], 'next_page' => null]),
        '*/contacts*' => Http::response(['contacts' => [], 'next_page' => null]),
        '*/projects*' => Http::response(['projects' => [['id' => 1, 'client' => ['id' => 77], 'name' => 'Web', 'code' => 'WEB', 'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0]], 'next_page' => null]),
        '*/invoices*' => Http::response(['invoices' => [[
            'id' => 9, 'client' => ['id' => 77], 'number' => '2025-001', 'state' => 'paid', 'currency' => 'CHF',
            'issue_date' => '2025-01-01', 'amount' => 108.1, 'tax' => 8.1, 'tax_amount' => 8.1,
            'line_items' => [['id' => 1, 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'taxed' => true]],
        ]], 'next_page' => null]),
        '*/estimates*' => Http::response(['estimates' => [], 'next_page' => null]),
    ]);
}

test('errors when credentials are missing', function () {
    config(['services.harvest.access_token' => null, 'services.harvest.account_id' => null]);

    $this->artisan('harvest:import')
        ->expectsOutputToContain('Missing Harvest credentials')
        ->assertExitCode(1);
});

test('--dry-run fetches and reports without writing', function () {
    fakeHarvest();

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(Client::count())->toBe(0);
    expect(Invoice::count())->toBe(0);
});

test('--force imports without prompting and reports a summary', function () {
    fakeHarvest();

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a', '--force' => true])
        ->assertExitCode(0);

    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeTrue();
    expect(Project::count())->toBe(1);
    expect(Invoice::where('number', '2025-001')->exists())->toBeTrue();
});

test('aborts when time entries exist and the user declines the confirmation', function () {
    fakeHarvest();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a'])
        ->expectsConfirmation('This will DELETE all clients, projects, invoices and estimates (and 1 time entr(y/ies) + their tasks). Continue?', 'no')
        ->assertExitCode(1);

    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeFalse();
});
