<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\Project;
use App\Services\Harvest\ClientImporter;
use App\Services\Harvest\EstimateImporter;
use App\Services\Harvest\HarvestData;
use App\Services\Harvest\ImportRunner;
use App\Services\Harvest\InvoiceImporter;
use App\Services\Harvest\ProjectImporter;

function runner(): ImportRunner
{
    return new ImportRunner(new ClientImporter(), new ProjectImporter(), new InvoiceImporter(), new EstimateImporter());
}

function sampleData(): HarvestData
{
    return new HarvestData(
        clients: [['id' => 77, 'name' => 'Atlas Robotics', 'is_active' => true]],
        contacts: [],
        projects: [['id' => 1, 'client' => ['id' => 77], 'name' => 'Web', 'code' => 'WEB', 'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0]],
        invoices: [[
            'id' => 9, 'client' => ['id' => 77], 'number' => '2025-001', 'state' => 'paid', 'currency' => 'CHF',
            'issue_date' => '2025-01-01', 'amount' => 108.1, 'tax' => 8.1, 'tax_amount' => 8.1,
            'line_items' => [['id' => 1, 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'taxed' => true]],
        ]],
        estimates: [],
    );
}

test('import creates rows, returns a summary, and bumps counters', function () {
    $summary = runner()->import(sampleData());

    expect($summary->clients)->toBe(1);
    expect($summary->projects)->toBe(1);
    expect($summary->invoices)->toBe(1);
    expect($summary->estimates)->toBe(0);
    expect(Invoice::first()->number)->toBe('2025-001');
    expect(InvoiceCounter::find(2025)->last_n)->toBe(1);
});

test('import wipes pre-existing clients/projects/invoices/estimates first', function () {
    $old = Client::factory()->create(['name' => 'Old Client']);
    Project::factory()->create(['client_id' => $old->id]);

    runner()->import(sampleData());

    expect(Client::where('name', 'Old Client')->exists())->toBeFalse();
    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeTrue();
    expect(Project::count())->toBe(1);
});

test('a failure inside the transaction rolls back, leaving existing data intact', function () {
    $old = Client::factory()->create(['name' => 'Keep Me']);

    // Two invoices share a number; the invoices.number UNIQUE index makes the
    // second insert throw a QueryException AFTER the wipe + first writes have run.
    $bad = new HarvestData(
        clients: [['id' => 77, 'name' => 'Atlas', 'is_active' => true]],
        contacts: [], projects: [], estimates: [],
        invoices: [
            ['id' => 9,  'client' => ['id' => 77], 'number' => 'DUP-001', 'state' => 'draft', 'currency' => 'CHF', 'issue_date' => '2025-01-01', 'amount' => 0, 'tax' => 0, 'tax_amount' => 0, 'line_items' => []],
            ['id' => 10, 'client' => ['id' => 77], 'number' => 'DUP-001', 'state' => 'draft', 'currency' => 'CHF', 'issue_date' => '2025-01-01', 'amount' => 0, 'tax' => 0, 'tax_amount' => 0, 'line_items' => []],
        ],
    );

    expect(fn () => runner()->import($bad))->toThrow(\Illuminate\Database\QueryException::class);

    // Rolled back: the wipe was undone, original client still present, no new ones.
    expect(Client::where('name', 'Keep Me')->exists())->toBeTrue();
    expect(Client::where('name', 'Atlas')->exists())->toBeFalse();
    expect(\App\Models\Invoice::where('number', 'DUP-001')->exists())->toBeFalse();
});
