<?php

use App\Models\BusinessProfile;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\CamtException;
use App\Services\Banking\StatementImporter;
use Tests\Fixtures\CamtXml;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'iban' => 'CH93 0076 2011 6238 5295 7']);
    $this->importer = app(StatementImporter::class);
});

test('stores a statement with its entries; credits start unmatched', function () {
    $result = $this->importer->import(file_get_contents(base_path('tests/Fixtures/camt/statement-04.xml')), 'a.xml');

    expect($result)->toMatchArray(['statements_added' => 1, 'lines_added' => 5, 'lines_known' => 0]);
    expect(Statement::first()->original_filename)->toBe('a.xml');

    $credit = StatementLine::where('bank_ref', 'T260000000002')->first();
    expect($credit->match_state)->toBe('unmatched');
    expect($credit->amount_rappen)->toBe(256520);
    expect($credit->booked_on->toDateString())->toBe('2026-07-03');
    expect(StatementLine::where('bank_ref', 'T260000000001')->first()->match_state)->toBeNull();
});

test('importing the same file twice adds nothing', function () {
    $xml = file_get_contents(base_path('tests/Fixtures/camt/statement-04.xml'));
    $this->importer->import($xml, 'a.xml');
    $again = $this->importer->import($xml, 'renamed.xml');

    expect($again)->toMatchArray(['statements_added' => 0, 'statements_known' => 1, 'lines_added' => 0, 'lines_known' => 5]);
    expect(Statement::count())->toBe(1);
    expect(StatementLine::count())->toBe(5);
});

test('an entry already known from another statement is skipped', function () {
    $this->importer->import(CamtXml::statement('2026-07-01', 1, '0', '100', [['ref' => 'A1', 'amount' => '100']]), 'a.xml');
    $result = $this->importer->import(CamtXml::statement('2026-07-02', 2, '100', '150', [
        ['ref' => 'A1', 'amount' => '100'], ['ref' => 'A2', 'amount' => '50'],
    ]), 'b.xml');

    expect($result)->toMatchArray(['lines_added' => 1, 'lines_known' => 1]);
});

test('a statement of another account is rejected and nothing is stored', function () {
    $xml = CamtXml::statement('2026-07-01', 1, '0', '100', [['ref' => 'A1', 'amount' => '100']], 'CH5604835012345678009');

    expect(fn () => $this->importer->import($xml, 'private.xml'))->toThrow(CamtException::class);
    expect(Statement::count())->toBe(0);
});

test('import is refused while no business IBAN is set', function () {
    BusinessProfile::current()->update(['iban' => null]);

    expect(fn () => $this->importer->import(CamtXml::statement('2026-07-01', 1, '0', '0', []), 'a.xml'))
        ->toThrow(CamtException::class);
});

test('gaps are found where balances do not connect, not where days are merely quiet', function () {
    $this->importer->import(CamtXml::statement('2026-07-01', 1, '0', '100', [['ref' => 'A1', 'amount' => '100']]), 'a.xml');
    // 2–5 July had no movement: no files, balance carries over.
    $this->importer->import(CamtXml::statement('2026-07-06', 4, '100', '150', [['ref' => 'A2', 'amount' => '50']]), 'b.xml');
    // Statements in between are missing: opening 400 != closing 150.
    $this->importer->import(CamtXml::statement('2026-07-20', 14, '400', '410', [['ref' => 'A3', 'amount' => '10']]), 'c.xml');

    expect(StatementImporter::gaps())->toBe([['after' => '2026-07-06', 'before' => '2026-07-20']]);
});
