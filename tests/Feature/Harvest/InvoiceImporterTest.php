<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Harvest\InvoiceImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client];
});

function harvestInvoice(array $overrides = []): array
{
    return array_merge([
        'id' => 1001, 'client' => ['id' => 77], 'number' => '2025-014',
        'state' => 'open', 'currency' => 'CHF',
        'issue_date' => '2025-03-01', 'due_date' => '2025-03-31',
        'sent_at' => '2025-03-01T09:00:00Z', 'paid_at' => null,
        'tax' => 8.1, 'tax_amount' => 8.1, 'tax2' => null, 'tax2_amount' => null,
        'amount' => 108.1, 'subject' => 'Website relaunch', 'notes' => 'Thanks',
        'line_items' => [
            ['id' => 1, 'description' => 'Consulting', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'taxed' => true],
        ],
    ], $overrides);
}

test('preserves number, maps open->sent, copies totals, writes lines + created event', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice()], $this->clientMap);

    expect($result['imported'])->toBe(1);
    $inv = Invoice::first();
    expect($inv->number)->toBe('2025-014');
    expect($inv->title)->toBe('Website relaunch');
    expect($inv->client_id)->toBe($this->client->id);
    expect($inv->project_id)->toBeNull();
    expect($inv->status)->toBe('sent');
    expect($inv->issued_on->toDateString())->toBe('2025-03-01');
    expect($inv->due_on->toDateString())->toBe('2025-03-31');
    expect((float) $inv->vat_rate)->toBe(8.1);
    expect($inv->total_rappen)->toBe(10810);
    expect($inv->vat_rappen)->toBe(810);
    expect($inv->subtotal_rappen)->toBe(10000); // total - vat
    expect($inv->lines)->toHaveCount(1);
    expect($inv->lines->first()->rate_rappen)->toBe(10000);
    expect($inv->events()->where('kind', 'created')->count())->toBe(1);
});

test('maps every Harvest state to an ernte status', function () {
    $importer = new InvoiceImporter();
    $importer->import([
        harvestInvoice(['id' => 1, 'number' => 'D1', 'state' => 'draft']),
        harvestInvoice(['id' => 2, 'number' => 'O1', 'state' => 'open']),
        harvestInvoice(['id' => 3, 'number' => 'P1', 'state' => 'paid']),
        harvestInvoice(['id' => 4, 'number' => 'C1', 'state' => 'closed']),
    ], $this->clientMap);

    expect(Invoice::where('number', 'D1')->value('status'))->toBe('draft');
    expect(Invoice::where('number', 'O1')->value('status'))->toBe('sent');
    expect(Invoice::where('number', 'P1')->value('status'))->toBe('paid');
    expect(Invoice::where('number', 'C1')->value('status'))->toBe('void');
});

test('untaxed line items produce a warning', function () {
    $inv = harvestInvoice(['line_items' => [
        ['id' => 1, 'description' => 'Reimbursement', 'quantity' => 1.0, 'unit_price' => 50.0, 'amount' => 50.0, 'taxed' => false],
    ]]);

    $result = (new InvoiceImporter())->import([$inv], $this->clientMap);

    expect(Invoice::first()->lines)->toHaveCount(1);
    expect($result['warnings'])->not->toBeEmpty();
});

test('non-CHF invoices are imported with a warning', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice(['currency' => 'USD'])], $this->clientMap);

    expect($result['imported'])->toBe(1);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Invoice::first()->currency)->toBe('USD');
});

test('skips an invoice whose client was not imported', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice(['client' => ['id' => 99999]])], $this->clientMap);

    expect($result['imported'])->toBe(0);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Invoice::count())->toBe(0);
});

test('skips an invoice with negative amounts (credit note)', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice([
        'number' => 'CN-1', 'amount' => -108.1,
        'line_items' => [['id' => 1, 'description' => 'Credit', 'quantity' => 1.0, 'unit_price' => -100.0, 'amount' => -100.0, 'taxed' => true]],
    ])], $this->clientMap);

    expect($result['imported'])->toBe(0);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Invoice::count())->toBe(0);
});
