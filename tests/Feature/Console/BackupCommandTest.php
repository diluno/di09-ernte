<?php

use App\Models\Backup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

afterEach(fn () => Carbon::setTestNow());

test('backup command creates dump artifacts and writes a backup row', function () {
    Storage::fake('local');
    Storage::disk('local')->put('invoices/2026-001.pdf', '%PDF-test');
    Storage::disk('local')->put('estimates/OF-2026-001.pdf', '%PDF-estimate');

    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')
        ->expectsOutputToContain('Backup created at backups/')
        ->assertExitCode(0);

    $backup = Backup::first();
    expect($backup)->not->toBeNull();
    expect($backup->size_bytes)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($backup->path.'/database.sql.gz');
    Storage::disk('local')->assertExists($backup->path.'/manifest.json');
    Storage::disk('local')->assertExists($backup->path.'/invoices.tar.gz');
    Storage::disk('local')->assertExists($backup->path.'/estimates.tar.gz');

    $estimateArchive = new PharData(Storage::disk('local')->path($backup->path.'/estimates.tar.gz'));
    expect(isset($estimateArchive['estimates/OF-2026-001.pdf']))->toBeTrue();
});

test('backup command succeeds without an invoices directory', function () {
    Storage::fake('local');
    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')->assertExitCode(0);

    $backup = Backup::first();
    Storage::disk('local')->assertExists($backup->path.'/database.sql.gz');
    Storage::disk('local')->assertMissing($backup->path.'/invoices.tar.gz');
});

test('backup command returns failure and writes no row when dump fails', function () {
    Storage::fake('local');
    Process::fake(fn () => Process::result('', 'no mysqldump', 1));

    $this->artisan('ernte:backup')
        ->expectsOutputToContain('Database dump failed')
        ->assertExitCode(1);

    expect(Backup::count())->toBe(0);
});

test('backup command mirrors verified artifacts to the configured off-server disk', function () {
    Storage::fake('local');
    Storage::fake('backup-mirror');
    config()->set('backup.mirror_disk', 'backup-mirror');
    Storage::disk('local')->put('estimates/OF-2026-001.pdf', '%PDF-estimate');
    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')->assertExitCode(0);

    $backup = Backup::first();
    Storage::disk('backup-mirror')->assertExists($backup->path.'/database.sql.gz');
    Storage::disk('backup-mirror')->assertExists($backup->path.'/estimates.tar.gz');
    Storage::disk('backup-mirror')->assertExists($backup->path.'/manifest.json');
});

test('backup command prunes local and mirrored backups beyond retention', function () {
    Carbon::setTestNow('2026-09-08 03:00:00');
    Storage::fake('local');
    Storage::fake('backup-mirror');
    config()->set('backup.mirror_disk', 'backup-mirror');
    config()->set('backup.retention_days', 30);
    Process::fake(fn () => Process::result('SQL DUMP'));

    $expiredPath = 'backups/20260701-030000';
    Storage::disk('local')->put("{$expiredPath}/manifest.json", '{}');
    Storage::disk('backup-mirror')->put("{$expiredPath}/manifest.json", '{}');
    $expired = Backup::create([
        'path' => $expiredPath,
        'size_bytes' => 2,
        'created_at' => now()->subDays(31),
    ]);

    $this->artisan('ernte:backup')->assertExitCode(0);

    expect(Backup::find($expired->id))->toBeNull();
    Storage::disk('local')->assertMissing("{$expiredPath}/manifest.json");
    Storage::disk('backup-mirror')->assertMissing("{$expiredPath}/manifest.json");
});

test('backup verify command detects corrupt artifacts', function () {
    Storage::fake('local');
    Process::fake(fn () => Process::result('SQL DUMP'));
    $this->artisan('ernte:backup')->assertExitCode(0);

    $backup = Backup::first();
    $this->artisan('ernte:backup:verify', ['path' => $backup->path])
        ->expectsOutputToContain('Backup verified')
        ->assertExitCode(0);

    Storage::disk('local')->put($backup->path.'/database.sql.gz', 'broken');
    $this->artisan('ernte:backup:verify', ['path' => $backup->path])
        ->expectsOutputToContain('Backup verification failed')
        ->assertExitCode(1);
});
