<?php

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\StatementLine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\CamtXml;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'iban' => CamtXml::IBAN]);
    $this->actingAs(User::factory()->create());
});

function camtUpload(string $name, string $xml): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $xml);
}

function julyStatement(): string
{
    return CamtXml::statement('2026-07-03', 125, '100', '2765.20', [
        ['ref' => 'D1', 'amount' => '100', 'credit' => false, 'name' => 'Carshare'],
        ['ref' => 'C1', 'amount' => '2565.20', 'text' => 'Rechnung 2026-008', 'name' => 'Atlas Robotics AG'],
        ['ref' => 'C2', 'amount' => '200', 'name' => 'Unknown Payer'],
    ]);
}

test('bank routes require login', function () {
    auth()->logout();
    $this->get('/bank')->assertRedirect('/login');
    $this->post('/bank/match')->assertRedirect('/login');
});

test('upload stores entries, reports rejected files, and matching marks the invoice paid', function () {
    $invoice = Invoice::factory()->sent()->create(['number' => '2026-008', 'total_rappen' => 256520, 'issued_on' => '2026-07-02']);

    $this->post('/bank/statements', ['files' => [
        camtUpload('2026-07-03.xml', julyStatement()),
        camtUpload('broken.xml', 'not xml'),
    ]])->assertOk()
        ->assertJsonPath('files.0.ok', true)
        ->assertJsonPath('files.0.lines_added', 3)
        ->assertJsonPath('files.1.ok', false);

    expect($invoice->fresh()->status)->toBe('sent'); // upload alone never matches

    $this->post('/bank/match')->assertOk()->assertJson(['matched' => 1, 'proposed' => 0, 'unmatched' => 1]);

    expect($invoice->fresh()->status)->toBe('paid');
    expect($invoice->fresh()->paid_at->toDateString())->toBe('2026-07-03');
});

test('GET /bank lists months with positions, the review queue and gaps', function () {
    Invoice::factory()->sent()->create(['number' => '2026-008', 'total_rappen' => 256520, 'issued_on' => '2026-07-02']);
    $this->post('/bank/statements', ['files' => [
        camtUpload('a.xml', julyStatement()),
        camtUpload('b.xml', CamtXml::statement('2026-07-20', 137, '9000', '9010', [['ref' => 'C3', 'amount' => '10']])),
    ]]);
    $this->post('/bank/match');

    $this->get('/bank')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bank/Index')
            ->where('year', 2026)
            ->has('months', 1)
            ->where('months.0.key', '2026-07')
            ->has('months.0.lines', 4)
            ->where('months.0.lines.0.position', 1)
            ->where('months.0.lines.0.is_credit', false)
            ->where('months.0.lines.1.invoice.number', '2026-008')
            ->where('months.0.lines.1.match_method', 'number_in_text')
            ->where('months.0.lines.3.position', 4)
            ->has('review', 2)
            ->where('gaps', [['after' => '2026-07-03', 'before' => '2026-07-20']])
            ->has('invoice_options', 1));
});

test('a proposal can be confirmed by hand, undone, and the entry ignored', function () {
    $invoice = Invoice::factory()->sent()->create(['number' => '2026-099', 'total_rappen' => 99900, 'issued_on' => '2026-07-01']);
    $this->post('/bank/statements', ['files' => [camtUpload('a.xml', julyStatement())]]);
    $line = StatementLine::where('bank_ref', 'C2')->first();

    $this->post("/bank/lines/{$line->id}/match", ['invoice_id' => $invoice->id])->assertRedirect();
    expect($line->fresh()->match_method)->toBe('manual');
    expect($invoice->fresh()->status)->toBe('paid');

    $this->post("/bank/lines/{$line->id}/ignore")->assertSessionHas('error');

    $this->post("/bank/lines/{$line->id}/unmatch")->assertRedirect();
    expect($invoice->fresh()->status)->toBe('sent');

    $this->post("/bank/lines/{$line->id}/ignore")->assertRedirect();
    expect($line->fresh()->match_state)->toBe('ignored');

    $this->post("/bank/lines/{$line->id}/ignore", ['ignored' => false]);
    expect($line->fresh()->match_state)->toBe('unmatched');
});

test('a note can be saved on any entry and cleared', function () {
    $this->post('/bank/statements', ['files' => [camtUpload('a.xml', julyStatement())]]);
    $debit = StatementLine::where('bank_ref', 'D1')->first();

    $this->patch("/bank/lines/{$debit->id}/note", ['note' => 'Refund of the Harvest invoice'])->assertRedirect();
    expect($debit->fresh()->note)->toBe('Refund of the Harvest invoice');

    $this->patch("/bank/lines/{$debit->id}/note", ['note' => ''])->assertRedirect();
    expect($debit->fresh()->note)->toBeNull();
});

test('the invoice page shows its bank payment', function () {
    Invoice::factory()->sent()->create(['number' => '2026-008', 'total_rappen' => 256520, 'issued_on' => '2026-07-02']);
    $this->post('/bank/statements', ['files' => [camtUpload('a.xml', julyStatement())]]);
    $this->post('/bank/match');

    $this->get('/invoices/2026-008')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.payments.0.bank_ref', 'C1')
            ->where('invoice.payments.0.booked_on', '2026-07-03')
            ->where('invoice.payments.0.method', 'number_in_text'));
});

test('a Viseca export uploads through the same endpoint and shows as a card bill under its month', function () {
    $bank = CamtXml::statement('2026-07-29', 143, '5000', '4579.45', [
        ['ref' => 'VD1', 'amount' => '420.55', 'credit' => false, 'name' => 'Viseca Payment Services SA'],
    ]);
    $later = CamtXml::statement('2026-08-03', 146, '4579.45', '4589.45', [['ref' => 'X1', 'amount' => '10']]);
    $card = Tests\Fixtures\VisecaCsv::make([
        ['T04', '2026-07-22 19:54:26', '120.950', '144.000', 'USD', 'Sketch'],
        ['T03', '2026-07-04 12:44:08', '208.950', '208.970', 'CHF', 'Exoscale'],
        ['T02', '2026-06-29 14:55:04', '90.650', '108.100', 'USD', 'ChatGPT'],
        ['T09', '2026-08-01 10:00:00', '16.200', '18.940', 'USD', 'Hover'],
    ]);
    $receipt = App\Models\Receipt::create(['original_name' => 'sketch.pdf', 'content_hash' => hash('sha256', 's'), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'extraction_status' => 'done', 'filing_status' => 'filed', 'vendor' => 'Sketch', 'document_date' => '2026-07-22', 'total_minor' => 14400, 'currency' => 'USD']);

    $this->post('/bank/statements', ['files' => [camtUpload('a.xml', $bank), camtUpload('b.xml', $later), camtUpload('Transactions.csv', $card)]])
        ->assertOk()->assertJsonPath('files.2.kind', 'card')->assertJsonPath('files.2.lines_added', 4);
    $this->post('/bank/match')->assertOk()->assertJsonPath('receipts.proposed', 1)->assertJsonPath('receipts.confident', 1);

    $this->get('/bank')->assertInertia(fn (Assert $page) => $page
        ->where('months.1.key', '2026-07')
        ->where('months.1.complete', true)
        ->where('months.0.complete', false)
        ->where('months.1.sections.0.lines.0.card_bill', 'imported')
        ->where('months.1.sections.1.kind', 'card')
        ->has('months.1.sections.1.lines', 3)
        ->where('months.1.sections.1.lines.0.counterparty', 'ChatGPT')
        ->where('months.1.sections.1.lines.0.position', 1)
        ->where('months.1.sections.1.lines.2.original', ['amount' => 144, 'currency' => 'USD'])
        ->where('months.1.sections.1.lines.2.receipts.0.state', 'proposed')
        ->where('months.1.missing', 4)
        ->where('months.1.confident', 1)
        ->has('pool', 1));

    $this->post('/bank/months/2026-07/confirm')->assertRedirect()->assertSessionHas('success', '1 match(es) confirmed.');
    expect($receipt->fresh()->match_state)->toBe('matched');

    $this->post("/bank/receipts/{$receipt->id}/unmatch")->assertRedirect();
    expect($receipt->fresh()->statement_line_id)->toBeNull();

    $line = StatementLine::where('bank_ref', 'T03')->first();
    $this->post("/bank/receipts/{$receipt->id}/match", ['line_id' => $line->id])->assertRedirect();
    expect($receipt->fresh())->statement_line_id->toBe($line->id)->match_method->toBe('manual');

    $this->post("/bank/lines/{$line->id}/no-receipt")->assertRedirect();
    expect($line->fresh()->no_receipt)->toBeTrue();
});

test('the bank page shows one quarter at a time, the latest by default', function () {
    $this->post('/bank/statements', ['files' => [
        camtUpload('q2.xml', CamtXml::statement('2026-06-29', 120, '0', '10', [['ref' => 'Q2A', 'amount' => '10']])),
        camtUpload('q3.xml', CamtXml::statement('2026-07-03', 125, '10', '30', [['ref' => 'Q3A', 'amount' => '20']])),
    ]]);

    $this->get('/bank')->assertInertia(fn (Assert $page) => $page
        ->where('quarter', ['key' => '2026-Q3', 'label' => 'Q3 2026'])
        ->where('quarters', [['key' => '2026-Q3', 'label' => 'Q3 2026'], ['key' => '2026-Q2', 'label' => 'Q2 2026']])
        ->has('months', 1)->where('months.0.key', '2026-07'));
    $this->get('/bank?quarter=2026-Q2')->assertInertia(fn (Assert $page) => $page
        ->where('quarter.key', '2026-Q2')->has('months', 1)->where('months.0.key', '2026-06'));
    $this->get('/bank?year=2026')->assertInertia(fn (Assert $page) => $page->where('quarter.key', '2026-Q3'));
    $this->get('/bank?quarter=nonsense')->assertInertia(fn (Assert $page) => $page->where('quarter.key', '2026-Q3'));
});
