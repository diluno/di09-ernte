<?php

use App\Services\Banking\Camt053Parser;
use App\Services\Banking\CamtException;
use Tests\Fixtures\CamtXml;

function camtFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/camt/{$name}"));
}

test('reads the statement header and balances', function () {
    [$statement] = (new Camt053Parser)->parse(camtFixture('statement-04.xml'));

    expect($statement['account_iban'])->toBe('CH9300762011623852957');
    expect($statement['statement_ref'])->toBe('9000000125');
    expect($statement['sequence_number'])->toBe(125);
    expect($statement['from_date'])->toBe('2026-07-03');
    expect($statement['to_date'])->toBe('2026-07-03');
    expect($statement['opening_balance_rappen'])->toBe(1000050);
    expect($statement['closing_balance_rappen'])->toBe(1161435);
});

test('reads each kind of entry and skips pending ones', function () {
    [$statement] = (new Camt053Parser)->parse(camtFixture('statement-04.xml'));
    $entries = collect($statement['entries'])->keyBy('bank_ref');

    expect($entries)->toHaveCount(5); // the PDNG entry is not booked

    $debit = $entries['T260000000001'];
    expect($debit['is_credit'])->toBeFalse();
    expect($debit['amount_rappen'])->toBe(24435);
    expect($debit['creditor_reference'])->toBe('210000000003139471430009017');
    expect($debit['counterparty_name'])->toBe('Carshare Genossenschaft');
    expect($debit['bank_tx_code'])->toBe('AUTT');

    $credit = $entries['T260000000002'];
    expect($credit['is_credit'])->toBeTrue();
    expect($credit['amount_rappen'])->toBe(256520);
    expect($credit['remittance_text'])->toBe('Rechnung 2026-008');
    expect($credit['creditor_reference'])->toBeNull();
    expect($credit['counterparty_name'])->toBe('Atlas Robotics AG');
    expect($credit['booked_on'])->toBe('2026-07-03');
    expect($credit['entry_index'])->toBe(1);
    expect($credit['transactions'])->toBeNull();

    $card = $entries['T260000000003'];
    expect($card['counterparty_name'])->toBe('EXAMPLEHOST.COM 00000 AMSTERDAM');
    expect($card['value_on'])->toBe('2026-07-02');

    expect($entries['T260000000004']['is_fee'])->toBeTrue();
    expect($entries['T260000000004']['amount_rappen'])->toBe(700);
    expect($entries['T260000000005']['remittance_text'])->toBe('Miete Büro 4');
});

test('reads version 08, negative balances and keeps a collective booking apart', function () {
    [$statement] = (new Camt053Parser)->parse(camtFixture('statement-08.xml'));

    expect($statement['opening_balance_rappen'])->toBe(-12000);
    expect($statement['entries'])->toHaveCount(2);

    [$single, $collective] = $statement['entries'];
    expect($single['creditor_reference'])->toBe('000000000000000000000001236');
    expect($single['counterparty_name'])->toBe('Nordlicht Verein');
    expect($single['remittance_text'])->toBe('Danke');

    expect($collective['transactions'])->toHaveCount(2);
    expect($collective['creditor_reference'])->toBeNull();
    expect($collective['transactions'][1]['amount_rappen'])->toBe(60000);
    expect($collective['transactions'][1]['creditor_reference'])->toBe('000000000000000000000012389');
});

test('converts amounts without floats', function (string $amount, int $rappen) {
    expect(Camt053Parser::toRappen($amount))->toBe($rappen);
})->with([
    ['3891.6', 389160],
    ['7', 700],
    ['35132.50', 3513250],
    ['0.05', 5],
    ['8129.120', 812912],
]);

test('rejects an amount with real sub-rappen digits', function () {
    Camt053Parser::toRappen('1.005');
})->throws(CamtException::class);

test('rejects files that are not camt.053', function (string $xml) {
    (new Camt053Parser)->parse($xml);
})->throws(CamtException::class)->with([
    'not xml' => ['hello'],
    'other namespace' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04"><BkToCstmrDbtCdtNtfctn/></Document>'],
    'doctype' => ['<!DOCTYPE Document [<!ENTITY x "y">]>'.substr(CamtXml::statement('2026-07-01', 1, '0', '0', []), 38)],
    'no statement' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04"><BkToCstmrStmt><GrpHdr><MsgId>x</MsgId></GrpHdr></BkToCstmrStmt></Document>'],
]);
