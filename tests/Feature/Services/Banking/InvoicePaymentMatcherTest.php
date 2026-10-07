<?php

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\InvoicePaymentMatcher;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->matcher = app(InvoicePaymentMatcher::class);
    $this->statement = Statement::create([
        'source' => 'zkb', 'account_iban' => 'CH9300762011623852957', 'message_id' => 'm', 'statement_ref' => 's',
        'sequence_number' => 1, 'from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'original_filename' => 'x.xml',
    ]);
});

function credit(string $date, int $rappen, array $attrs = []): StatementLine
{
    static $n = 0;
    $n++;

    return StatementLine::create($attrs + [
        'statement_id' => test()->statement->id, 'source' => 'zkb', 'bank_ref' => "REF{$n}", 'entry_index' => $n,
        'booked_on' => $date, 'is_credit' => true, 'amount_rappen' => $rappen, 'currency' => 'CHF', 'match_state' => 'unmatched',
    ]);
}

function sentInvoice(string $number, int $rappen, string $issued = '2026-07-01', array $attrs = []): Invoice
{
    return Invoice::factory()->create($attrs + [
        'number' => $number, 'status' => 'sent', 'total_rappen' => $rappen,
        'issued_on' => $issued, 'due_on' => '2026-12-31', 'sent_at' => $issued,
    ]);
}

test('QR reference and amount: invoice is marked paid on the booking date', function () {
    $invoice = sentInvoice('2026-030', 100000, attrs: ['qr_reference' => '000000000000000000000001236']);
    sentInvoice('2026-031', 100000); // same amount, would make pass 3 ambiguous
    $line = credit('2026-08-10', 100000, ['creditor_reference' => '000000000000000000000001236']);

    $result = $this->matcher->run();

    expect($result['matched'])->toBe(1);
    $line->refresh();
    $invoice->refresh();
    expect($line->match_state)->toBe('matched');
    expect($line->match_method)->toBe('qr_reference');
    expect($line->invoice_id)->toBe($invoice->id);
    expect($line->marked_invoice_paid)->toBeTrue();
    expect($invoice->status)->toBe('paid');
    expect($invoice->paid_at->toDateString())->toBe('2026-08-10');
    expect($invoice->events()->where('kind', 'paid')->first()->payload)
        ->toMatchArray(['statement_line_id' => $line->id, 'method' => 'qr_reference']);
});

test('QR reference with a different amount is only proposed', function () {
    $invoice = sentInvoice('2026-030', 100000, attrs: ['qr_reference' => '000000000000000000000001236']);
    $line = credit('2026-08-10', 90000, ['creditor_reference' => '000000000000000000000001236']);

    $this->matcher->run();

    $line->refresh();
    expect($line->match_state)->toBe('proposed');
    expect($line->match_note)->toBe('amount_mismatch');
    expect($line->invoice_id)->toBe($invoice->id);
    expect($invoice->fresh()->status)->toBe('sent');
});

test('invoice number in the text and amount', function (string $text) {
    $invoice = sentInvoice('2026-012', 2421440);
    sentInvoice('2026-013', 2421440);
    $line = credit('2026-08-26', 2421440, ['remittance_text' => $text]);

    $this->matcher->run();

    expect($line->fresh()->match_method)->toBe('number_in_text');
    expect($line->fresh()->invoice_id)->toBe($invoice->id);
})->with(['Rechnung 2026-012', 'RECHNUNG 2026-012', '.2026-012 / Umsetzung Plattform', '30.06.2026 #2026-012']);

test('a number inside a longer run of digits does not count', function () {
    expect(InvoicePaymentMatcher::textNames('Ref 12026-0125', '2026-012'))->toBeFalse();
    expect(InvoicePaymentMatcher::textNames('043 259 47 44', '44'))->toBeFalse();
    expect(InvoicePaymentMatcher::textNames('365/DIENSTSTELLE', '365'))->toBeTrue();
});

test('amount that fits exactly one invoice', function () {
    $invoice = sentInvoice('2026-009', 340515);
    sentInvoice('2026-010', 64860);
    $line = credit('2026-07-30', 340515);

    $this->matcher->run();

    expect($line->fresh()->match_method)->toBe('amount');
    expect($invoice->fresh()->status)->toBe('paid');
});

test('same-amount pair: the text decides one, which leaves the other for the amount pass', function () {
    $a = sentInvoice('2026-004', 64860, '2026-06-18');
    $b = sentInvoice('2026-010', 64860, '2026-07-06');
    $first = credit('2026-08-04', 64860, ['remittance_text' => '06.07.2026 362']);
    $second = credit('2026-08-10', 64860, ['remittance_text' => 'RECHNUNG 2026-004']);

    $result = $this->matcher->run();

    expect($result['matched'])->toBe(2);
    expect($second->fresh()->invoice_id)->toBe($a->id);
    expect($first->fresh()->invoice_id)->toBe($b->id);
    expect($first->fresh()->match_method)->toBe('amount');
});

test('several invoices fit one credit: proposal only', function () {
    sentInvoice('2026-004', 64860);
    sentInvoice('2026-010', 64860);
    $line = credit('2026-08-04', 64860);

    $result = $this->matcher->run();

    expect($result)->toMatchArray(['matched' => 0, 'proposed' => 1]);
    expect($line->fresh()->match_note)->toBe('several_candidates');
    expect(Invoice::where('status', 'paid')->count())->toBe(0);
});

test('two credits competing for one invoice: neither is applied', function () {
    sentInvoice('2026-016', 129720);
    $a = credit('2026-09-16', 129720);
    $b = credit('2026-09-21', 129720);

    $result = $this->matcher->run();

    expect($result['matched'])->toBe(0);
    expect($a->fresh()->match_state)->toBe('proposed');
    expect($b->fresh()->match_state)->toBe('proposed');
});

test('a credit booked before the invoice was issued is not an amount candidate', function () {
    $invoice = sentInvoice('2026-016', 129720, '2026-09-15');
    $mistaken = credit('2026-09-14', 129720);
    $real = credit('2026-09-21', 129720);

    $this->matcher->run();

    expect($mistaken->fresh()->match_state)->toBe('unmatched');
    expect($real->fresh()->invoice_id)->toBe($invoice->id);
});

test('an invoice marked paid by hand is linked and its date corrected', function () {
    $invoice = sentInvoice('2026-018', 138370, '2026-09-15', ['status' => 'paid', 'paid_at' => '2026-09-23 09:00:00']);
    $line = credit('2026-09-21', 138370);

    $this->matcher->run();

    $line->refresh();
    $invoice->refresh();
    expect($line->match_state)->toBe('matched');
    expect($line->marked_invoice_paid)->toBeFalse();
    expect($invoice->paid_at->toDateString())->toBe('2026-09-21');
    $event = $invoice->events()->where('kind', 'payment_matched')->first();
    expect($event->payload['previous_paid_at'])->toContain('2026-09-23');
});

test('an invoice paid by hand long ago is not an amount candidate', function () {
    sentInvoice('2026-002', 129720, '2026-06-01', ['status' => 'paid', 'paid_at' => '2026-06-08 10:00:00']);
    $line = credit('2026-09-14', 129720);

    $this->matcher->run();

    expect($line->fresh()->match_state)->toBe('unmatched');
});

test('draft and void invoices never match; debits, reversals and collective bookings are left alone', function () {
    sentInvoice('2026-040', 50000, attrs: ['status' => 'void']);
    sentInvoice('2026-041', 60000, attrs: ['status' => 'draft']);
    sentInvoice('2026-042', 70000);
    $void = credit('2026-08-10', 50000, ['remittance_text' => 'Rechnung 2026-040']);
    $draft = credit('2026-08-10', 60000);
    $debit = credit('2026-08-10', 70000, ['is_credit' => false, 'match_state' => null]);
    $reversal = credit('2026-08-10', 70000, ['is_reversal' => true]);
    $collective = credit('2026-08-10', 70000, ['transactions' => [['amount_rappen' => 70000]]]);

    $result = $this->matcher->run();

    expect($result['matched'])->toBe(0);
    expect($void->fresh()->match_state)->toBe('unmatched');
    expect($draft->fresh()->match_state)->toBe('unmatched');
    expect($debit->fresh()->match_state)->toBeNull();
    expect($reversal->fresh()->invoice_id)->toBeNull();
    expect($collective->fresh()->invoice_id)->toBeNull();
});

test('running again changes nothing and keeps ignored entries ignored', function () {
    sentInvoice('2026-009', 340515);
    sentInvoice('2026-004', 64860);
    sentInvoice('2026-010', 64860);
    credit('2026-07-30', 340515);
    credit('2026-08-04', 64860);
    $ignored = credit('2026-08-05', 1000, ['match_state' => 'ignored']);

    $this->matcher->run();
    $before = StatementLine::orderBy('id')->get(['id', 'invoice_id', 'match_state', 'match_method', 'match_note'])->toArray();
    $again = $this->matcher->run();

    expect($again['matched'])->toBe(0);
    expect(StatementLine::orderBy('id')->get(['id', 'invoice_id', 'match_state', 'match_method', 'match_note'])->toArray())->toBe($before);
    expect($ignored->fresh()->match_state)->toBe('ignored');
    expect(Invoice::find(Invoice::where('number', '2026-009')->value('id'))->events()->where('kind', 'paid')->count())->toBe(1);
});

test('unmatch reopens an invoice the import had marked paid and stops re-matching', function () {
    $invoice = sentInvoice('2026-009', 340515);
    $line = credit('2026-07-30', 340515);
    $this->matcher->run();

    $this->matcher->unmatch($line->fresh());

    expect($invoice->fresh()->status)->toBe('sent');
    expect($invoice->fresh()->paid_at)->toBeNull();
    expect($invoice->events()->where('kind', 'reopened')->count())->toBe(1);

    $this->matcher->run();
    expect($line->fresh()->match_state)->toBe('unmatched');
    expect($invoice->fresh()->status)->toBe('sent');
});

test('unmatch leaves an invoice that was paid by hand as paid', function () {
    $invoice = sentInvoice('2026-018', 138370, '2026-09-15', ['status' => 'paid', 'paid_at' => '2026-09-21 09:00:00']);
    $line = credit('2026-09-21', 138370);
    $this->matcher->run();

    $this->matcher->unmatch($line->fresh());

    expect($invoice->fresh()->status)->toBe('paid');
});

test('a second credit can be linked by hand without moving the paid date', function () {
    $invoice = sentInvoice('2026-016', 129720, '2026-09-01');
    $first = credit('2026-09-10', 129720);
    $this->matcher->run();
    $second = credit('2026-09-21', 129720);

    $this->matcher->apply($second, $invoice->fresh(), 'manual');

    expect($second->fresh()->match_state)->toBe('matched');
    expect($invoice->fresh()->paid_at->toDateString())->toBe('2026-09-10');
    expect($invoice->bankEntries()->count())->toBe(2);
});

test('apply refuses debits, already matched entries and void invoices', function () {
    $invoice = sentInvoice('2026-009', 340515);
    $void = sentInvoice('2026-050', 100, attrs: ['status' => 'void']);
    $debit = credit('2026-07-30', 340515, ['is_credit' => false, 'match_state' => null]);
    $line = credit('2026-07-30', 340515);

    expect(fn () => $this->matcher->apply($debit, $invoice, 'manual'))->toThrow(\DomainException::class);
    expect(fn () => $this->matcher->apply($line, $void, 'manual'))->toThrow(\DomainException::class);
    $this->matcher->apply($line, $invoice, 'manual');
    expect(fn () => $this->matcher->apply($line->fresh(), $invoice->fresh(), 'manual'))->toThrow(\DomainException::class);
});

test('ignore and restore', function () {
    sentInvoice('2026-004', 64860);
    sentInvoice('2026-010', 64860);
    $line = credit('2026-08-04', 64860);
    $this->matcher->run(); // proposed

    $this->matcher->setIgnored($line->fresh(), true);
    expect($line->fresh()->match_state)->toBe('ignored');
    expect($line->fresh()->invoice_id)->toBeNull();

    $this->matcher->setIgnored($line->fresh(), false);
    expect($line->fresh()->match_state)->toBe('unmatched');
});
