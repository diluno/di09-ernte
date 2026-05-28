<?php

use App\Models\Backup;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

test('backup command creates dump artifacts and writes a backup row', function () {
    Storage::fake('local');
    Storage::disk('local')->put('invoices/2026-001.pdf', '%PDF-test');

    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')
        ->expectsOutputToContain('Backup created at backups/')
        ->assertExitCode(0);

    $backup = Backup::first();
    expect($backup)->not->toBeNull();
    expect($backup->size_bytes)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($backup->path . '/database.sql.gz');
    Storage::disk('local')->assertExists($backup->path . '/manifest.json');
    Storage::disk('local')->assertExists($backup->path . '/invoices.tar.gz');
});

test('backup command succeeds without an invoices directory', function () {
    Storage::fake('local');
    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')->assertExitCode(0);

    $backup = Backup::first();
    Storage::disk('local')->assertExists($backup->path . '/database.sql.gz');
    Storage::disk('local')->assertMissing($backup->path . '/invoices.tar.gz');
});

test('backup command returns failure and writes no row when dump fails', function () {
    Storage::fake('local');
    Process::fake(fn () => Process::result('', 'no mysqldump', 1));

    $this->artisan('ernte:backup')
        ->expectsOutputToContain('Database dump failed')
        ->assertExitCode(1);

    expect(Backup::count())->toBe(0);
});
