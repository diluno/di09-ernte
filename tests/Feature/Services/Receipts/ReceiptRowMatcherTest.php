<?php

use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Receipts\ReceiptRowMatcher;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->matcher = app(ReceiptRowMatcher::class);
    $this->bank = Statement::create(['source' => 'zkb', 'account_iban' => 'CH93', 'message_id' => 'm', 'statement_ref' => 's', 'sequence_number' => 1, 'from_date' => '2026-07-01', 'to_date' => '2026-09-30', 'original_filename' => 'x.xml']);
    $this->card = Statement::create(['source' => 'viseca', 'account_iban' => 'VISECA', 'message_id' => 'pool', 'statement_ref' => 'pool', 'from_date' => '2026-07-01', 'to_date' => '2026-09-30', 'original_filename' => 'x.csv']);
});

function bankDebit(string $date, int $rappen, string $payee, array $attrs = []): StatementLine
{
    static $n = 0;
    $n++;

    return StatementLine::create($attrs + [
        'statement_id' => test()->bank->id, 'source' => 'zkb', 'bank_ref' => "B{$n}", 'entry_index' => $n, 'booked_on' => $date,
        'is_credit' => false, 'amount_rappen' => $rappen, 'currency' => 'CHF', 'counterparty_name' => $payee, 'bank_tx_code' => 'AUTT',
    ]);
}

function cardCharge(string $at, int $chf, int $original, string $currency, string $merchant, array $attrs = []): StatementLine
{
    static $n = 0;
    $n++;

    return StatementLine::create($attrs + [
        'statement_id' => test()->card->id, 'source' => 'viseca', 'bank_ref' => "T{$n}", 'entry_index' => $n,
        'booked_on' => substr($at, 0, 10), 'transacted_at' => $at.' 10:00:00', 'is_credit' => false,
        'amount_rappen' => $chf, 'currency' => 'CHF', 'original_amount_minor' => $original, 'original_currency' => $currency,
        'counterparty_name' => $merchant, 'description' => strtoupper($merchant), 'bank_tx_code' => 'CARD',
    ]);
}

function readReceipt(string $vendor, ?string $date, ?int $total, ?string $currency = 'CHF', array $attrs = []): Receipt
{
    static $n = 0;
    $n++;

    return Receipt::create($attrs + [
        'original_name' => "doc{$n}.pdf", 'content_hash' => hash('sha256', "m{$n}"), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'extraction_status' => 'done', 'filing_status' => 'filed', 'vendor' => $vendor, 'document_date' => $date,
        'total_minor' => $total, 'currency' => $currency, 'confidence' => 'high',
    ]);
}

test('a card receipt matches on the original amount and currency, not on the rounded CHF', function () {
    $row = cardCharge('2026-09-05', 4175, 4287, 'EUR', 'Hetzner Online GmbH');
    cardCharge('2026-09-06', 4290, 4290, 'CHF', 'Other Shop');
    $receipt = readReceipt('Hetzner', '2026-09-04', 4287, 'EUR');

    $result = $this->matcher->run();

    expect($result)->toMatchArray(['proposed' => 1, 'confident' => 1]);
    expect($receipt->fresh())->statement_line_id->toBe($row->id)->match_state->toBe('proposed')->match_method->toBe('total_and_name')->match_confident->toBeTrue();
});

test('a CHF card receipt still matches when the bill rounds to 5 rappen', function () {
    $row = cardCharge('2026-09-14', 8110, 8108, 'CHF', 'Paddle.net Kirby');
    $receipt = readReceipt('Kirby', '2026-09-14', 8108);

    $this->matcher->run();

    expect($receipt->fresh()->statement_line_id)->toBe($row->id);
});

test('two vendors charging the same amount are told apart by name', function () {
    $openai = cardCharge('2026-08-29', 9020, 10810, 'USD', 'ChatGPT', ['description' => 'OPENAI *CHATGPT SUBSCR']);
    $anthropic = cardCharge('2026-09-04', 9100, 10810, 'USD', 'ANTHROPIC* CLAUDE SUB');
    $a = readReceipt('Anthropic', '2026-09-04', 10810, 'USD');
    $o = readReceipt('OpenAI', '2026-08-29', 10810, 'USD');

    $this->matcher->run();

    expect($a->fresh()->statement_line_id)->toBe($anthropic->id);
    expect($o->fresh()->statement_line_id)->toBe($openai->id);
    expect($a->fresh()->match_confident)->toBeTrue();
});

test('the same subscription every month is paired in date order', function () {
    $july = cardCharge('2026-07-28', 1250, 1300, 'EUR', 'Molploi io');
    $august = cardCharge('2026-08-28', 1265, 1300, 'EUR', 'Molploi io');
    $r2 = readReceipt('ploi', '2026-08-28', 1300, 'EUR');
    $r1 = readReceipt('ploi', '2026-07-28', 1300, 'EUR');

    $this->matcher->run();

    expect($r1->fresh()->statement_line_id)->toBe($july->id);
    expect($r2->fresh()->statement_line_id)->toBe($august->id);
    expect($r1->fresh()->match_note)->toBeNull();
});

test('a bank payment is matched by the reference printed on the receipt', function () {
    $row = bankDebit('2026-07-03', 24435, 'Mobility Genossenschaft', ['creditor_reference' => '000000001405086200013419516']);
    bankDebit('2026-07-10', 24435, 'Someone Else');
    $receipt = readReceipt('Carshare', null, 24435, 'CHF', ['text_layer' => "Referenz 00 00000 01405 08620 00134 19516\nTotal 244.35"]);

    $this->matcher->run();

    expect($receipt->fresh())->statement_line_id->toBe($row->id)->match_method->toBe('reference')->match_confident->toBeTrue();
});

test('total alone matches only when it is unambiguous; otherwise the nearest row is offered', function () {
    $only = bankDebit('2026-07-10', 213450, 'XYZ Treuhand');
    $single = readReceipt('Steuerberatung Muster', '2026-07-01', 213450);
    $a = bankDebit('2026-08-05', 5880, 'Alpha AG');
    $b = bankDebit('2026-09-03', 5880, 'Beta AG');
    $unclear = readReceipt('Gamma', '2026-08-01', 5880);

    $this->matcher->run();

    expect($single->fresh())->statement_line_id->toBe($only->id)->match_method->toBe('total')->match_confident->toBeTrue();
    expect($unclear->fresh())->statement_line_id->toBe($a->id)->match_note->toBe('several_rows')->match_confident->toBeFalse();
});

test('another amount on the receipt needs the name too and is never confident', function () {
    $row = bankDebit('2026-07-21', 103770, 'Mobility Genossenschaft');
    $receipt = readReceipt('Mobility', '2026-07-15', 207540, 'CHF', ['amounts' => [['amount' => '2075.40', 'currency' => 'CHF'], ['amount' => '1037.70', 'currency' => 'CHF']]]);
    $stranger = readReceipt('Unrelated', '2026-07-15', 999, 'CHF', ['amounts' => [['amount' => '1037.70', 'currency' => 'CHF']]]);

    $this->matcher->run();

    expect($receipt->fresh())->statement_line_id->toBe($row->id)->match_method->toBe('amount_and_name')->match_confident->toBeFalse();
    expect($stranger->fresh()->statement_line_id)->toBeNull();
});

test('a foreign-currency debit-card purchase is offered by name and date, flagged', function () {
    $row = bankDebit('2026-07-02', 9670, 'DIGITALOCEAN.COM 00000 AMSTERDAM', ['bank_tx_code' => 'POSD']);
    $receipt = readReceipt('DigitalOcean', '2026-07-01', 11400, 'USD');

    $this->matcher->run();

    expect($receipt->fresh())->statement_line_id->toBe($row->id)->match_method->toBe('name_and_date')->match_note->toBe('amount_differs')->match_confident->toBeFalse();
});

test('rows outside the date window, fees, credits and rows marked as needing no receipt are never offered', function () {
    bankDebit('2026-01-10', 5000, 'Old AG');
    bankDebit('2026-07-10', 5000, 'Fee', ['is_fee' => true]);
    bankDebit('2026-07-10', 5000, 'Salary', ['no_receipt' => true]);
    bankDebit('2026-07-10', 5000, 'Client', ['is_credit' => true]);
    $receipt = readReceipt('Whoever', '2026-07-08', 5000);

    expect($this->matcher->run()['proposed'])->toBe(0);
    expect($receipt->fresh()->statement_line_id)->toBeNull();
});

function numbered(string $vendor, int $total, string $name, array $attrs = []): Receipt
{
    return readReceipt($vendor, '2026-06-20', $total, 'CHF', $attrs + ['filename' => $name, 'dropbox_path' => "/Diluno/Receipts/2026_Q3/07/{$name}"]);
}

test('a file already numbered is linked to the row with that number when the amount agrees', function () {
    bankDebit('2026-07-01', 100, 'First');
    $second = bankDebit('2026-07-02', 200, 'Second');
    $receipt = numbered('Anything', 200, '02_Rechnung.pdf');

    $this->matcher->run();

    expect($receipt->fresh())->statement_line_id->toBe($second->id)->match_method->toBe('existing_prefix')->match_confident->toBeTrue()->match_note->toBeNull();
});

test('when the numbered row shows another amount, the row with the right amount is offered and flagged', function () {
    // The old PDF order and the bank's order differ by one: file 02 belongs to the third row.
    bankDebit('2026-07-01', 100, 'First');
    $second = bankDebit('2026-07-02', 55500, 'Somebody Else');
    $third = bankDebit('2026-07-03', 24435, 'Mobility Genossenschaft');
    $receipt = numbered('Mobility', 24435, '02_Mobility-Rechnung.pdf');

    $this->matcher->run();

    expect($receipt->fresh())->statement_line_id->toBe($third->id)->match_method->toBe('total_and_name')
        ->match_note->toBe('number_differs')->match_confident->toBeFalse();
    expect($second->receipts()->count())->toBe(0);
});

test('a numbered file whose amount fits no row keeps the row its number names, unconfirmed', function () {
    bankDebit('2026-07-01', 100, 'First');
    $rent = bankDebit('2026-07-02', 59455, 'Hausverwaltung', ['remittance_text' => 'Miete']);
    $contract = numbered('Mietvertrag', 0, '02_mietvertrag-buero.pdf', ['total_minor' => null]);

    $this->matcher->run();

    expect($contract->fresh())->statement_line_id->toBe($rent->id)->match_method->toBe('existing_prefix')
        ->match_note->toBe('number_only')->match_confident->toBeFalse();
});

test('running again changes nothing; confirmed and undone matches are respected', function () {
    $row = cardCharge('2026-09-05', 4175, 4287, 'EUR', 'Hetzner Online GmbH');
    $receipt = readReceipt('Hetzner', '2026-09-04', 4287, 'EUR');
    $this->matcher->run();
    $this->matcher->run();
    expect($receipt->fresh()->statement_line_id)->toBe($row->id);

    $this->matcher->confirm($receipt->fresh(), $row);
    expect($receipt->fresh())->match_state->toBe('matched')->match_method->toBe('total_and_name');
    $this->matcher->run();
    expect($receipt->fresh()->match_state)->toBe('matched');

    // A second receipt can share the row by hand.
    $extra = readReceipt('Hetzner', '2026-09-04', 1, 'EUR');
    $this->matcher->confirm($extra, $row);
    expect($row->receipts()->where('match_state', 'matched')->count())->toBe(2);
    expect($extra->fresh()->match_method)->toBe('manual');

    $this->matcher->unmatch($receipt->fresh());
    $this->matcher->run();
    expect($receipt->fresh())->statement_line_id->toBeNull()->auto_match_disabled->toBeTrue();
});

test('receipts that are unread or duplicates are left out', function () {
    cardCharge('2026-09-05', 4175, 4287, 'EUR', 'Hetzner Online GmbH');
    $unread = readReceipt('Hetzner', '2026-09-04', 4287, 'EUR', ['extraction_status' => 'pending']);

    expect($this->matcher->run()['proposed'])->toBe(0);
    expect($unread->fresh()->match_state)->toBeNull();
});

test('significant words drop boilerplate and numbers', function () {
    expect(ReceiptRowMatcher::words('Hetzner Online GmbH Rechnung 089001033983.pdf'))->toBe(['hetzner']);
    expect(ReceiptRowMatcher::words('Mol*ploi io'))->toBe(['ploi']);
});
