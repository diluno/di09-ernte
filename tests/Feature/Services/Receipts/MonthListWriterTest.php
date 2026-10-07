<?php

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\MonthList;
use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxException;
use App\Services\Receipts\MonthListWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts', 'inbox_folder' => '_Inbox']]);
    BusinessProfile::create(['name' => 'Diluno GmbH', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::put('dropbox.access_token', 'access-1', 600);
    Http::preventStrayRequests();

    $sep = Statement::create(['source' => 'zkb', 'account_iban' => 'CH93', 'message_id' => 'm', 'statement_ref' => 'sep', 'sequence_number' => 10, 'from_date' => '2026-09-02', 'to_date' => '2026-09-30', 'opening_balance_rappen' => 0, 'closing_balance_rappen' => 100, 'original_filename' => 's.xml']);
    Statement::create(['source' => 'zkb', 'account_iban' => 'CH93', 'message_id' => 'm', 'statement_ref' => 'oct', 'sequence_number' => 20, 'from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'opening_balance_rappen' => 100, 'closing_balance_rappen' => 100, 'original_filename' => 'o.xml']);
    $line = fn (int $i, string $date, array $attrs) => StatementLine::create($attrs + [
        'statement_id' => $sep->id, 'source' => 'zkb', 'bank_ref' => "B{$i}", 'entry_index' => $i, 'booked_on' => $date, 'is_credit' => false, 'amount_rappen' => 10000, 'currency' => 'CHF',
    ]);
    $invoice = Invoice::factory()->paid()->create(['number' => '2026-011', 'total_rappen' => 194580, 'dropbox_path' => '/Diluno/Receipts/2026_Q3/09/01_Diluno-GmbH-Rechnung-2026-011.pdf', 'dropbox_file_id' => 'id:inv']);
    $line(1, '2026-09-02', ['is_credit' => true, 'amount_rappen' => 194580, 'counterparty_name' => 'Kanton Zürich', 'match_state' => 'matched', 'invoice_id' => $invoice->id]);
    $this->digitec = $line(2, '2026-09-03', ['amount_rappen' => 8490, 'counterparty_name' => 'Digitec Galaxus AG']);
    $line(3, '2026-09-14', ['is_credit' => true, 'amount_rappen' => 129720, 'counterparty_name' => 'Abalir AG', 'match_state' => 'ignored', 'note' => 'Irrtümliche Zahlung der Harvest-Rechnung 364, am 30.09. zurückbezahlt']);
    $line(4, '2026-09-16', ['amount_rappen' => 250840, 'counterparty_name' => 'SVA Zürich', 'remittance_text' => 'Akonto Q3']);
    $line(5, '2026-09-28', ['amount_rappen' => 719885, 'counterparty_name' => null, 'description' => 'Belastung Salär', 'no_receipt' => true]);
    $line(6, '2026-09-29', ['amount_rappen' => 700, 'description' => 'Kontoführung', 'is_fee' => true]);
    $debit = $line(7, '2026-09-29', ['amount_rappen' => 3000, 'counterparty_name' => 'Viseca Payment Services SA']);

    Receipt::create(['original_name' => 'Digitec.pdf', 'filename' => '02_Digitec_Rechnung_86028900.pdf', 'content_hash' => hash('sha256', 'd'), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'statement_line_id' => $this->digitec->id, 'match_state' => 'matched', 'note' => 'Monitor fürs Büro']);
    Receipt::create(['original_name' => 'p.pdf', 'filename' => 'Vorschlag.pdf', 'content_hash' => hash('sha256', 'p'), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'statement_line_id' => $debit->id, 'match_state' => 'proposed']);

    $bill = Statement::create(['source' => 'viseca', 'account_iban' => 'VISECA', 'message_id' => 'bill', 'statement_ref' => 'B7', 'bank_line_id' => $debit->id, 'charges_rappen' => 3000, 'from_date' => '2026-08-27', 'to_date' => '2026-09-05', 'original_filename' => 'v.csv']);
    $card = fn (int $i, string $at, int $chf, int $orig, string $cur, string $merchant, bool $credit = false) => StatementLine::create([
        'statement_id' => $bill->id, 'source' => 'viseca', 'bank_ref' => "T{$i}", 'entry_index' => $i, 'booked_on' => substr($at, 0, 10), 'transacted_at' => $at, 'is_credit' => $credit,
        'amount_rappen' => $chf, 'currency' => 'CHF', 'original_amount_minor' => $orig, 'original_currency' => $cur, 'counterparty_name' => $merchant, 'bank_tx_code' => 'CARD',
    ]);
    $hetzner = $card(1, '2026-09-05 06:46:58', 4175, 4287, 'EUR', 'Hetzner Online GmbH');
    $card(2, '2026-08-27 03:31:19', 1665, 2000, 'USD', 'Netlify');
    $card(3, '2026-09-01 00:00:00', 500, 500, 'CHF', 'Bonus', true);
    Receipt::create(['original_name' => 'h.pdf', 'filename' => '02_Hetzner_2026-09-04.pdf', 'content_hash' => hash('sha256', 'h'), 'original_mime' => 'application/pdf', 'size_bytes' => 1,
        'statement_line_id' => $hetzner->id, 'match_state' => 'matched']);

    $this->writer = app(MonthListWriter::class);
});

test('the list names, per row, its number, document and note, in German', function () {
    $data = $this->writer->data(2026, 9);

    expect($data)->title->toBe('Belegliste September 2026')->company->toBe('Diluno GmbH')->count->toBe(10)->missing->toBe(3);
    [$bank, $card] = $data['sections'];

    expect($bank['title'])->toBe('Bankkonto');
    expect(array_column($bank['rows'], 'number'))->toBe(['01', '02', '03', '04', '05', '06', '07']);
    expect($bank['rows'][0])->documents->toBe(['01_Diluno-GmbH-Rechnung-2026-011.pdf'])->amount->toBe("1'945.80")->date->toBe('02.09.');
    expect($bank['rows'][1])->documents->toBe(['02_Digitec_Rechnung_86028900.pdf'])->note->toBe('Monitor fürs Büro')->amount->toBe('−84.90');
    expect($bank['rows'][2])->documents->toBe(['keine Rechnungszahlung'])->status->toBe('ok');
    expect($bank['rows'][2]['note'])->toContain('Harvest-Rechnung 364');
    expect($bank['rows'][3])->documents->toBe(['Beleg fehlt'])->status->toBe('missing')->text->toBe('SVA Zürich · Akonto Q3');
    expect($bank['rows'][4])->documents->toBe(['kein Beleg nötig'])->text->toBe('Belastung Salär');
    expect($bank['rows'][5]['documents'])->toBe(['Bankgebühr, kein Beleg']);
    // A proposal is not a document yet.
    expect($bank['rows'][6])->documents->toBe(['Beleg fehlt'])->status->toBe('missing');

    expect($card['title'])->toBe('Kreditkarte');
    expect($card['meta'])->toContain('29.09.2026')->toContain('30.00');
    expect(array_column($card['rows'], 'number'))->toBe(['01', '–', '02']); // by transaction time; the credit has none
    expect($card['rows'][0])->text->toBe('Netlify')->original->toBe('20.00 USD')->status->toBe('missing');
    expect($card['rows'][1])->documents->toBe(['Gutschrift, nicht nummeriert'])->amount->toBe('−5.00');
    expect($card['rows'][2])->documents->toBe(['02_Hetzner_2026-09-04.pdf'])->original->toBe('42.87 EUR');
});

test('the list renders as a PDF', function () {
    expect($this->writer->render($this->writer->data(2026, 9)))->toStartWith('%PDF');
});

/** The writer with rendering stubbed out, so the Dropbox traffic can be tested quickly. */
function fastWriter(): MonthListWriter
{
    $writer = new class(app(DropboxClient::class), app(App\Services\Banking\StatementPositions::class)) extends MonthListWriter
    {
        public function render(array $data): string
        {
            return '%PDF list';
        }
    };
    app()->instance(MonthListWriter::class, $writer);

    return $writer;
}

function uploadArgs(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'files/upload'))->map(fn ($p) => json_decode($p[0]->header('Dropbox-API-Arg')[0], true))->values()->all();
}

test('the first write adds the list to the month folder; the next one replaces that very file', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:list', 'name' => 'Belegliste.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf']),
        'content.dropboxapi.com/2/files/upload' => Http::response(['id' => 'id:list', 'name' => '_Belegliste 2026-09.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf']),
    ]);
    $writer = fastWriter();

    $list = $writer->write(2026, 9);
    expect($list)->dropbox_file_id->toBe('id:list')->dropbox_path->toBe('/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf');
    expect(uploadArgs()[0])->toMatchArray(['path' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf', 'mode' => 'add', 'autorename' => false]);

    $writer->write(2026, 9);
    expect(uploadArgs()[1])->toMatchArray(['path' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf', 'mode' => 'overwrite']);
    expect(MonthList::count())->toBe(1);
});

test('a deleted list is written anew; a foreign file of that name is never replaced', function () {
    MonthList::create(['year' => 2026, 'month' => 9, 'dropbox_file_id' => 'id:gone', 'dropbox_path' => '/x', 'written_at' => now()]);
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['error_summary' => 'path/not_found/.'], 409),
        'content.dropboxapi.com/2/files/upload' => Http::sequence()
            ->push(['id' => 'id:new', 'name' => '_Belegliste 2026-09.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf'])
            ->push(['error_summary' => 'path/conflict/file/.'], 409),
    ]);
    $writer = fastWriter();

    expect($writer->write(2026, 9)->dropbox_file_id)->toBe('id:new');
    expect(uploadArgs()[0]['mode'])->toBe('add');

    MonthList::query()->update(['dropbox_file_id' => 'id:gone']);
    expect(fn () => $writer->write(2026, 9))->toThrow(DomainException::class, 'did not write');
});

test('no list is written for an incomplete month', function () {
    Http::fake();
    expect(fn () => fastWriter()->write(2026, 10))->toThrow(DomainException::class, 'not complete');
    Http::assertNothingSent();
});

test('replace only works by id, inside the receipts folder', function () {
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:x', 'name' => 'tax.pdf', 'path_display' => '/Private/tax.pdf'])]);
    $dropbox = app(DropboxClient::class);

    expect(fn () => $dropbox->replace('/Diluno/Receipts/2026_Q3/09/a.pdf', 'x'))->toThrow(DropboxException::class);
    expect(fn () => $dropbox->replace('id:x', 'x'))->toThrow(DropboxException::class);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'files/upload'));
});

test('the bank page offers the list and shows when it was written', function () {
    $this->actingAs(User::factory()->create());
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'content.dropboxapi.com/2/files/upload' => Http::response(['id' => 'id:list', 'name' => '_Belegliste 2026-09.pdf', 'path_display' => '/Diluno/Receipts/2026_Q3/09/_Belegliste 2026-09.pdf']),
    ]);
    fastWriter();

    $this->get('/bank?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p->where('months.0.list_written_at', null));
    $this->post('/bank/months/2026-09/list')->assertRedirect()->assertSessionHas('success', 'List written: _Belegliste 2026-09.pdf');
    $this->get('/bank?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p->whereNot('months.0.list_written_at', null));
    $this->post('/bank/months/2026-10/list')->assertSessionHas('error');
});
