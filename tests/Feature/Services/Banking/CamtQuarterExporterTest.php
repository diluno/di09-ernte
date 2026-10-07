<?php

use App\Models\BusinessProfile;
use App\Models\QuarterFile;
use App\Models\Statement;
use App\Models\User;
use App\Services\Banking\Camt053Parser;
use App\Services\Banking\CamtQuarterExporter;
use App\Services\Banking\StatementImporter;
use App\Support\Quarter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\CamtXml;

beforeEach(function () {
    config(['services.dropbox' => ['app_key' => 'key', 'app_secret' => 'secret', 'receipts_root' => '/Diluno/Receipts', 'inbox_folder' => '_Inbox']]);
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'iban' => CamtXml::IBAN, 'dropbox_refresh_token' => 'refresh-1']);
    Cache::put('dropbox.access_token', 'access-1', 600);
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->importer = app(StatementImporter::class);
    $this->exporter = app(CamtQuarterExporter::class);
    $this->q3 = new Quarter(2026, 3);
});

function importQuarter(bool $withOctober = true): void
{
    $i = test()->importer;
    $i->import(CamtXml::statement('2026-06-29', 120, '900.00', '1000.00', [['ref' => 'JUN1', 'amount' => '100.00']]), 'jun.xml');
    $i->import(CamtXml::statement('2026-07-03', 125, '1000.00', '1320.85', [
        ['ref' => 'JUL1', 'amount' => '244.35', 'credit' => false, 'name' => 'Carshare', 'qrr' => '210000000003139471430009017'],
        ['ref' => 'JUL2', 'amount' => '565.20', 'text' => 'Rechnung 2026-008', 'name' => 'Atlas Robotics AG'],
    ]), 'jul03.xml');
    $i->import(CamtXml::statement('2026-08-27', 163, '1320.85', '1220.85', [['ref' => 'AUG1', 'amount' => '100.00', 'credit' => false]]), 'aug27.xml');
    $i->import(CamtXml::statement('2026-09-30', 188, '1220.85', '1227.85', [['ref' => 'SEP1', 'amount' => '7.00']]), 'sep30.xml');
    if ($withOctober) {
        $i->import(CamtXml::statement('2026-10-01', 189, '1227.85', '1237.85', [['ref' => 'OCT1', 'amount' => '10.00']]), 'oct01.xml');
    }
}

test('the daily files of a quarter become one statement for exactly that quarter', function () {
    importQuarter();

    $xml = $this->exporter->build($this->q3);
    $statements = (new Camt053Parser)->parse($xml);

    expect($statements)->toHaveCount(1);
    $s = $statements[0];
    expect($s)->from_date->toBe('2026-07-01')->to_date->toBe('2026-09-30')->statement_ref->toBe('2026-Q3')
        ->opening_balance_rappen->toBe(100000)->closing_balance_rappen->toBe(122785)->account_iban->toBe(CamtXml::IBAN);
    // Every entry of the quarter, in the bank's order, none from June or October.
    expect(array_column($s['entries'], 'bank_ref'))->toBe(['JUL1', 'JUL2', 'AUG1', 'SEP1']);
    // Entries are the bank's own, not rewritten.
    expect($s['entries'][0])->creditor_reference->toBe('210000000003139471430009017')->counterparty_name->toBe('Carshare')->amount_rappen->toBe(24435);
    expect($s['entries'][1]['remittance_text'])->toBe('Rechnung 2026-008');
    expect($xml)->toContain('urn:iso:std:iso:20022:tech:xsd:camt.053.001.04')->toContain('<MsgId>ERNTE-2026Q3-');
});

test('a quarter is not exported while it is incomplete, has a gap, or lacks original files', function () {
    importQuarter(withOctober: false);
    expect($this->exporter->blocker($this->q3))->toContain('not complete');

    $this->importer->import(CamtXml::statement('2026-10-01', 189, '1227.85', '1237.85', [['ref' => 'OCT1', 'amount' => '10.00']]), 'oct01.xml');
    expect($this->exporter->blocker($this->q3))->toBeNull();
    expect($this->exporter->blocker(new Quarter(2026, 1)))->toContain('No bank statements');

    Statement::where('statement_ref', 'STMT-163')->update(['raw_path' => null]);
    expect($this->exporter->blocker($this->q3))->toContain('original files are not stored for 1 of 3 days');

    // Uploading the same file again supplies the original without importing anything twice.
    $again = $this->importer->import(CamtXml::statement('2026-08-27', 163, '1320.85', '1220.85', [['ref' => 'AUG1', 'amount' => '100.00', 'credit' => false]]), 'aug27.xml');
    expect($again)->toMatchArray(['statements_added' => 0, 'lines_added' => 0]);
    expect($this->exporter->blocker($this->q3))->toBeNull();

    Statement::where('statement_ref', 'STMT-163')->delete();
    expect($this->exporter->blocker($this->q3))->toContain('Statements are missing between 03.07.2026 and 30.09.2026');
});

test('the file is written to the quarter folder and replaced on the next click', function () {
    $this->actingAs(User::factory()->create());
    importQuarter();
    $path = '/Diluno/Receipts/2026_Q3/_camt053_2026_Q3.xml';
    Http::fake([
        'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => []]),
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:camt', 'name' => basename($path), 'path_display' => $path]),
        'content.dropboxapi.com/2/files/upload' => Http::response(['id' => 'id:camt', 'name' => basename($path), 'path_display' => $path]),
    ]);

    $this->get('/bank?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p->where('camt_file', ['written_at' => null, 'blocker' => null]));
    $this->post('/bank/quarters/2026-Q3/camt')->assertRedirect()->assertSessionHas('success', 'camt file written: _camt053_2026_Q3.xml');
    $this->post('/bank/quarters/2026-Q3/camt')->assertSessionHas('success');

    $uploads = Http::recorded(fn (Request $r) => str_contains($r->url(), 'files/upload'))->values();
    expect(json_decode($uploads[0][0]->header('Dropbox-API-Arg')[0], true))->toMatchArray(['path' => $path, 'mode' => 'add']);
    expect(json_decode($uploads[1][0]->header('Dropbox-API-Arg')[0], true))->toMatchArray(['path' => $path, 'mode' => 'overwrite']);
    expect($uploads[0][0]->body())->toContain('<Document')->toContain('SEP1');
    expect(QuarterFile::count())->toBe(1);
    $this->get('/bank?quarter=2026-Q3')->assertInertia(fn (Assert $p) => $p->whereNot('camt_file.written_at', null));

    $this->post('/bank/quarters/2026-Q4/camt')->assertSessionHas('error');
    $this->post('/bank/quarters/nonsense/camt')->assertNotFound();
});
