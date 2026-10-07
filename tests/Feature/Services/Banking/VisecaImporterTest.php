<?php

use App\Models\BusinessProfile;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\CamtException;
use App\Services\Banking\StatementPositions;
use App\Services\Banking\VisecaImporter;
use Tests\Fixtures\VisecaCsv;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->viseca = app(VisecaImporter::class);
    $this->bank = Statement::create([
        'source' => 'zkb', 'account_iban' => 'CH9300762011623852957', 'message_id' => 'm', 'statement_ref' => 's',
        'sequence_number' => 1, 'from_date' => '2026-07-01', 'to_date' => '2026-07-31', 'original_filename' => 'x.xml',
    ]);
});

function visecaDebit(string $date, int $rappen, string $payee = 'Viseca Payment Services SA'): StatementLine
{
    static $n = 0;
    $n++;

    return StatementLine::create([
        'statement_id' => test()->bank->id, 'source' => 'zkb', 'bank_ref' => "ZKB{$n}", 'entry_index' => $n,
        'booked_on' => $date, 'is_credit' => false, 'amount_rappen' => $rappen, 'currency' => 'CHF', 'counterparty_name' => $payee,
    ]);
}

function threeMonths(): string
{
    return VisecaCsv::make([
        ['T09', '2026-09-20 10:49:09', '16.200', '18.940', 'USD', 'Hover'],
        ['T08', '2026-08-28 05:49:59', '12.650', '13.000', 'EUR', 'Molploi io'],
        ['T07', '2026-08-21 00:00:00', '-42.500', '-42.500', 'CHF', 'Bonus'],
        ['T06', '2026-08-21 00:00:00', '85.000', '85.000', 'CHF', 'Jahresgebuehr'],
        ['T05', '2026-08-04 10:53:56', '188.250', '188.230', 'CHF', 'Exoscale'],
        ['T04', '2026-07-22 19:54:26', '120.950', '144.000', 'USD', 'Sketch'],
        ['T03', '2026-07-04 12:44:08', '208.950', '208.970', 'CHF', 'Exoscale'],
        ['T02', '2026-06-29 14:55:04', '90.650', '108.100', 'USD', 'ChatGPT'],
        ['P01', '2026-07-29 18:18:25', '-420.550', '-420.550', 'CHF', 'Ihre Zahlung - Danke'],
    ]);
}

test('rows are stored with exact original amounts; payments are set apart', function () {
    $result = $this->viseca->import(threeMonths(), 'export.csv');

    expect($result)->toMatchArray(['lines_added' => 9, 'lines_known' => 0, 'bills_assigned' => 0]);
    $hover = StatementLine::where('bank_ref', 'T09')->first();
    expect($hover)->source->toBe('viseca')->amount_rappen->toBe(1620)->original_amount_minor->toBe(1894)->original_currency->toBe('USD');
    expect($hover->transacted_at->format('Y-m-d H:i'))->toBe('2026-09-20 10:49');
    expect($hover->counterparty_name)->toBe('Hover');
    expect(StatementLine::where('bank_ref', 'T07')->first())->is_credit->toBeTrue()->amount_rappen->toBe(4250)->bank_tx_code->toBe('CARD');
    expect(StatementLine::where('bank_ref', 'P01')->first())->bank_tx_code->toBe('PAYMENT')->counterparty_name->toBe('IHRE ZAHLUNG - DANKE');
});

test('an export spanning several bills is cut along the bank debits; credits count towards the sum', function () {
    $july = visecaDebit('2026-07-29', 42055);   // T02 + T03 + T04
    $august = visecaDebit('2026-08-27', 23075); // T05 + T06 − T07
    visecaDebit('2026-08-01', 59455, 'Hausverwaltung'); // not Viseca: never a bill

    $result = $this->viseca->import(threeMonths(), 'export.csv');

    expect($result['bills_assigned'])->toBe(2);
    $bills = Statement::where('source', 'viseca')->whereNotNull('bank_line_id')->orderBy('to_date')->get();
    expect($bills[0])->bank_line_id->toBe($july->id)->charges_rappen->toBe(42055);
    expect($bills[0]->lines()->pluck('bank_ref')->sort()->values()->all())->toBe(['T02', 'T03', 'T04']);
    expect($bills[1]->lines()->pluck('bank_ref')->sort()->values()->all())->toBe(['T05', 'T06', 'T07']);
    // September's rows and the payment row stay unbilled.
    expect(Statement::where('statement_ref', 'pool')->first()->lines()->pluck('bank_ref')->sort()->values()->all())->toBe(['P01', 'T08', 'T09']);
});

test('importing again, or an overlapping export, adds nothing twice; a later bank debit completes the bill', function () {
    visecaDebit('2026-07-29', 42055);
    $this->viseca->import(threeMonths(), 'a.csv');

    $again = $this->viseca->import(threeMonths(), 'b.csv');
    expect($again)->toMatchArray(['lines_added' => 0, 'lines_known' => 9, 'bills_assigned' => 0]);
    expect(StatementLine::where('source', 'viseca')->count())->toBe(9);

    visecaDebit('2026-08-27', 23075);
    expect($this->viseca->assignBills())->toBe(1);
    expect($this->viseca->assignBills())->toBe(0);
});

test('no bill is invented when the rows do not add up to the debit', function () {
    visecaDebit('2026-07-29', 99999);

    expect($this->viseca->import(threeMonths(), 'a.csv')['bills_assigned'])->toBe(0);
    expect(Statement::where('source', 'viseca')->whereNotNull('bank_line_id')->count())->toBe(0);
});

test('card rows are numbered by transaction time, oldest first; credits get no number', function () {
    visecaDebit('2026-08-27', 23075);
    $this->viseca->import(threeMonths(), 'a.csv');
    $bill = Statement::where('source', 'viseca')->whereNotNull('bank_line_id')->first();

    $numbers = app(StatementPositions::class)->bill($bill)->mapWithKeys(fn ($l) => [$l->bank_ref => $l->number])->all();

    expect($numbers)->toBe(['T05' => 1, 'T06' => 2, 'T07' => null]);
    expect(StatementPositions::prefix(7))->toBe('07');
    expect(StatementPositions::prefix(112))->toBe('112');
});

test('a bill can be dissolved back into unbilled rows', function () {
    visecaDebit('2026-07-29', 42055);
    $this->viseca->import(threeMonths(), 'a.csv');
    $bill = Statement::where('source', 'viseca')->whereNotNull('bank_line_id')->first();

    $this->viseca->dissolve($bill);

    expect(Statement::where('source', 'viseca')->whereNotNull('bank_line_id')->count())->toBe(0);
    expect(StatementLine::where('source', 'viseca')->count())->toBe(9);
});

test('files that are not the Viseca export are rejected', function (string $csv) {
    $this->viseca->import($csv, 'x.csv');
})->throws(CamtException::class)->with([
    'other csv' => ["Date,Text,Amount\n2026-01-01,x,1.00\n"],
    'broken row' => ["TransactionId,Date,Amount,Currency,OriginalAmount,OriginalCurrency,Details\nT1,2026-01-01\n"],
    'foreign billing currency' => ["TransactionId,Date,Amount,Currency,OriginalAmount,OriginalCurrency,Details\nT1,2026-01-01 10:00:00,1.000,EUR,1.000,EUR,X\n"],
]);

test('a bank month is complete only with a later statement and no gap inside it', function () {
    $positions = app(StatementPositions::class);
    expect($positions->bankMonthComplete(2026, 7))->toBeFalse(); // nothing after July yet

    Statement::create(['source' => 'zkb', 'account_iban' => 'CH9300762011623852957', 'message_id' => 'm', 'statement_ref' => 'aug',
        'sequence_number' => 2, 'from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'original_filename' => 'y.xml']);
    expect($positions->bankMonthComplete(2026, 7))->toBeTrue();
    expect($positions->bankMonthComplete(2026, 8))->toBeFalse();
});
