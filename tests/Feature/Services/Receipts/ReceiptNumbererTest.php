<?php

use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Banking\CamtException;
use App\Services\Banking\StatementImporter;
use App\Services\Receipts\ReceiptNumberer;
use App\Services\Receipts\ReceiptRowMatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CamtXml;

/** The fake Dropbox's files: id => name and path. */
final class NumbererDropbox
{
    public static array $files = [];
}

beforeEach(function () {
    Illuminate\Support\Facades\Cache::flush();
    NumbererDropbox::$files = [];
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts', 'inbox_folder' => '_Inbox']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1', 'iban' => CamtXml::IBAN]);
    Cache::put('dropbox.access_token', 'access-1', 600);
    Http::preventStrayRequests();
    $this->numberer = app(ReceiptNumberer::class);

    // July: three bank rows, the third being the Viseca debit; a statement in August makes July complete.
    $this->july = Statement::create(['source' => 'zkb', 'account_iban' => CamtXml::IBAN, 'message_id' => 'm', 'statement_ref' => 'jul', 'sequence_number' => 10, 'from_date' => '2026-07-03', 'to_date' => '2026-07-29', 'opening_balance_rappen' => 0, 'closing_balance_rappen' => 100, 'original_filename' => 'j.xml']);
    Statement::create(['source' => 'zkb', 'account_iban' => CamtXml::IBAN, 'message_id' => 'm', 'statement_ref' => 'aug', 'sequence_number' => 20, 'from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'opening_balance_rappen' => 100, 'closing_balance_rappen' => 100, 'original_filename' => 'a.xml']);
    $bank = fn (int $i, string $date, int $rappen, string $payee) => StatementLine::create([
        'statement_id' => $this->july->id, 'source' => 'zkb', 'bank_ref' => "B{$i}", 'entry_index' => $i, 'booked_on' => $date,
        'is_credit' => false, 'amount_rappen' => $rappen, 'currency' => 'CHF', 'counterparty_name' => $payee,
    ]);
    $this->row1 = $bank(1, '2026-07-03', 24435, 'Mobility');
    $this->row2 = $bank(2, '2026-07-06', 6120, 'Digitec Galaxus AG');
    $this->debit = $bank(3, '2026-07-29', 3000, 'Viseca Payment Services SA');

    $this->bill = Statement::create(['source' => 'viseca', 'account_iban' => 'VISECA', 'message_id' => 'bill', 'statement_ref' => 'B3', 'bank_line_id' => $this->debit->id, 'charges_rappen' => 3000, 'from_date' => '2026-06-29', 'to_date' => '2026-07-05', 'original_filename' => 'v.csv']);
    $card = fn (int $i, string $at, int $rappen, string $merchant, bool $credit = false) => StatementLine::create([
        'statement_id' => $this->bill->id, 'source' => 'viseca', 'bank_ref' => "T{$i}", 'entry_index' => $i, 'booked_on' => substr($at, 0, 10), 'transacted_at' => $at,
        'is_credit' => $credit, 'amount_rappen' => $rappen, 'currency' => 'CHF', 'original_amount_minor' => $rappen, 'original_currency' => 'CHF', 'counterparty_name' => $merchant, 'bank_tx_code' => 'CARD',
    ]);
    $this->cardLate = $card(1, '2026-07-05 06:06:39', 2000, 'Hetzner');
    $this->cardEarly = $card(2, '2026-06-29 14:55:04', 1500, 'ChatGPT');
    $this->cardCredit = $card(3, '2026-07-01 00:00:00', 500, 'Bonus', true);

});

/** A matched receipt with its file in the Dropbox fake. */
function filed(string $name, StatementLine $row, string $folder = '/Diluno/Receipts/2026_Q3/07'): Receipt
{
    static $n = 0;
    $n++;
    $id = "id:f{$n}";
    NumbererDropbox::$files[$id] = ['name' => $name, 'path' => "{$folder}/{$name}"];

    return Receipt::create([
        'original_name' => $name, 'filename' => $name, 'content_hash' => hash('sha256', "n{$n}"), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'extraction_status' => 'done', 'filing_status' => 'filed', 'dropbox_file_id' => $id, 'dropbox_path' => "{$folder}/{$name}",
        'target_year' => 2026, 'target_month' => 7, 'statement_line_id' => $row->id, 'match_state' => 'matched', 'match_method' => 'manual',
    ]);
}

/** Dropbox that remembers moves, so lookups by id see the new place; listed paths are taken. */
function fakeDropboxMoves(array $taken = []): void
{
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => function (Request $r) {
            $f = NumbererDropbox::$files[$r['path']] ?? null;

            return $f ? Http::response(['.tag' => 'file', 'id' => $r['path'], 'name' => $f['name'], 'path_display' => $f['path']])
                : Http::response(['error_summary' => 'path/not_found/.'], 409);
        },
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/move_v2' => function (Request $r) use ($taken) {
            if (in_array($r['to_path'], $taken, true)) {
                return Http::response(['error_summary' => 'to/conflict/file/.'], 409);
            }
            NumbererDropbox::$files[$r['from_path']] = ['name' => basename($r['to_path']), 'path' => $r['to_path']];

            return Http::response(['metadata' => ['.tag' => 'file', 'id' => $r['from_path'], 'name' => basename($r['to_path']), 'path_display' => $r['to_path']]]);
        },
    ]);
}

function movesSent(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'move_v2'))->map(fn ($p) => $p[0]['to_path'])->values()->all();
}

test('a bank receipt gets its row number as a prefix, in the month of the row', function () {
    fakeDropboxMoves();
    $receipt = filed('Digitec_Rechnung_82706262.pdf', $this->row2, '/Diluno/Receipts/2026_Q2/06'); // parked elsewhere

    $this->numberer->number($receipt);

    expect(movesSent())->toBe(['/Diluno/Receipts/2026_Q3/07/02_Digitec_Rechnung_82706262.pdf']);
    $receipt->refresh();
    expect($receipt)->filename->toBe('02_Digitec_Rechnung_82706262.pdf')->target_month->toBe(7)
        ->numbered_from_path->toBe('/Diluno/Receipts/2026_Q2/06/Digitec_Rechnung_82706262.pdf');
    expect($receipt->numbered_at)->not->toBeNull();
    expect($receipt->numberPrefix())->toBe('02');
    // The whole month's numbers are frozen with the first file.
    expect(StatementLine::where('source', 'zkb')->orderBy('entry_index')->pluck('position')->all())->toBe([1, 2, 3]);
});

test('a card receipt moves into Kreditkarte of the month its bill was paid, numbered by transaction time', function () {
    fakeDropboxMoves();
    $receipt = filed('Hetzner_2026-07-04.pdf', $this->cardLate);

    $this->numberer->number($receipt);

    expect(movesSent())->toBe(['/Diluno/Receipts/2026_Q3/07/Kreditkarte/02_Hetzner_2026-07-04.pdf']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_folder_v2') && $r['path'] === '/Diluno/Receipts/2026_Q3/07/Kreditkarte');
    expect($this->cardEarly->fresh()->position)->toBe(1);
    expect($this->cardCredit->fresh()->position)->toBeNull();
});

test('nothing is numbered while the month is incomplete, the match unconfirmed, or the row a credit', function () {
    Http::fake();
    $august = StatementLine::create(['statement_id' => $this->july->id, 'source' => 'zkb', 'bank_ref' => 'B9', 'entry_index' => 9, 'booked_on' => '2026-08-03', 'is_credit' => false, 'amount_rappen' => 100, 'currency' => 'CHF']);

    expect(fn () => $this->numberer->number(filed('aug.pdf', $august)))->toThrow(DomainException::class, 'not complete');
    $proposal = filed('p.pdf', $this->row1);
    $proposal->update(['match_state' => 'proposed']);
    expect(fn () => $this->numberer->number($proposal))->toThrow(DomainException::class);
    expect(fn () => $this->numberer->number(filed('bonus.pdf', $this->cardCredit)))->toThrow(DomainException::class, 'not numbered');
    Http::assertNothingSent();
    expect($this->numberer->blocker($august))->toContain('not complete');
    expect($this->numberer->blocker($this->row1))->toBeNull();
});

test('numbering a month skips a clash and carries on; two receipts may share a number', function () {
    fakeDropboxMoves(taken: ['/Diluno/Receipts/2026_Q3/07/01_Mobility.pdf']);
    $clash = filed('Mobility.pdf', $this->row1);
    $a = filed('Digitec A.pdf', $this->row2);
    $b = filed('Digitec B.pdf', $this->row2);
    $card = filed('ChatGPT.pdf', $this->cardEarly);

    $result = $this->numberer->numberMonth(2026, 7);

    expect($result['numbered'])->toBe(3);
    expect($result['skipped'])->toHaveCount(1);
    expect($result['skipped'][0])->toContain('Mobility.pdf')->toContain('already exists');
    expect($clash->fresh()->numbered_at)->toBeNull();
    expect([$a->fresh()->filename, $b->fresh()->filename])->toBe(['02_Digitec A.pdf', '02_Digitec B.pdf']);
    expect($card->fresh()->dropbox_path)->toBe('/Diluno/Receipts/2026_Q3/07/Kreditkarte/01_ChatGPT.pdf');
});

test('a file that already has the right number is only recorded; a different number is refused', function () {
    fakeDropboxMoves();
    $same = filed('02_Digitec.pdf', $this->row2);
    $other = filed('07_Mobility.pdf', $this->row1);

    $this->numberer->number($same);
    expect($same->fresh()->numbered_at)->not->toBeNull();
    expect(fn () => $this->numberer->number($other))->toThrow(DomainException::class, 'already numbered 07');
    expect(movesSent())->toBe([]);
});

test('undo takes the number off and puts the file back; a numbered receipt cannot be unmatched before that', function () {
    fakeDropboxMoves();
    $receipt = filed('Digitec.pdf', $this->row2, '/Diluno/Receipts/2026_Q2/06');
    $this->numberer->number($receipt);

    expect(fn () => app(ReceiptRowMatcher::class)->unmatch($receipt->fresh()))->toThrow(DomainException::class);

    $message = $this->numberer->undo($receipt->fresh());

    expect($message)->toContain('back in its month folder');
    expect($receipt->fresh())->filename->toBe('Digitec.pdf')->dropbox_path->toBe('/Diluno/Receipts/2026_Q2/06/Digitec.pdf')
        ->numbered_at->toBeNull()->target_month->toBe(6)->match_state->toBe('matched');
});

test('undo leaves a file alone that someone renamed in Dropbox since', function () {
    fakeDropboxMoves();
    $receipt = filed('Digitec.pdf', $this->row2);
    $this->numberer->number($receipt);
    NumbererDropbox::$files[$receipt->dropbox_file_id] = ['name' => 'Digitec final.pdf', 'path' => '/Diluno/Receipts/2026_Q3/07/Digitec final.pdf'];

    expect($this->numberer->undo($receipt->fresh()))->toContain('left as it is');
    expect(movesSent())->toHaveCount(1); // only the original numbering
    expect($receipt->fresh())->numbered_at->toBeNull()->filename->toBe('Digitec final.pdf');
});

test('once a month is numbered, a statement that would add a row to it is refused', function () {
    fakeDropboxMoves();
    $this->numberer->number(filed('Digitec.pdf', $this->row2));

    $late = CamtXml::statement('2026-07-15', 15, '0', '10', [['ref' => 'LATE1', 'amount' => '10']]);
    expect(fn () => app(StatementImporter::class)->import($late, 'late.xml'))->toThrow(CamtException::class, 'already numbered');
    expect(StatementLine::where('bank_ref', 'LATE1')->exists())->toBeFalse();
});

test('the month button numbers through the page and reports what was skipped', function () {
    $this->actingAs(User::factory()->create());
    fakeDropboxMoves(taken: ['/Diluno/Receipts/2026_Q3/07/01_Mobility.pdf']);
    filed('Mobility.pdf', $this->row1);
    $ok = filed('Digitec.pdf', $this->row2);

    // The run is queued one file per job (run inline in tests) and reports through the page.
    $this->post('/bank/months/2026-07/number')->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Numbering 2 file(s)'));
    expect($ok->fresh()->filename)->toBe('02_Digitec.pdf');
    $this->get('/bank?quarter=2026-Q3')->assertInertia(fn (Inertia\Testing\AssertableInertia $p) => $p
        ->where('months.0.numbering.total', 2)->where('months.0.numbering.done', 2)->where('months.0.numbering.running', false)
        ->where('months.0.numbering.skipped', fn ($skipped) => count($skipped) === 1 && str_contains($skipped[0], 'Mobility.pdf') && str_contains($skipped[0], 'already exists')));
    $this->post('/bank/months/2026-07/number')->assertSessionHas('success'); // Mobility is still pending; a finished run does not block a new one

    $this->post("/bank/receipts/{$ok->id}/unnumber")->assertRedirect()->assertSessionHas('success');
    expect($ok->fresh()->filename)->toBe('Digitec.pdf');
});
