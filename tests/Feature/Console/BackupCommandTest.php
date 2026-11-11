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

test('backup includes the stored bank statements and the receipts not yet in Dropbox, and the manifest counts them', function () {
    Storage::fake('local');
    Storage::disk('local')->put('invoices/2026-001.pdf', '%PDF-test');
    Storage::disk('local')->put('statements/abc.xml', '<Document/>');
    Storage::disk('local')->put('statements/def.xml', '<Document/>');
    Storage::disk('local')->put('receipts/hash.pdf', '%PDF-receipt');
    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')->assertExitCode(0);

    $backup = Backup::first();
    $statements = new PharData(Storage::disk('local')->path($backup->path.'/statements.tar.gz'));
    expect(isset($statements['statements/abc.xml']))->toBeTrue();
    $receipts = new PharData(Storage::disk('local')->path($backup->path.'/receipts.tar.gz'));
    expect(isset($receipts['receipts/hash.pdf']))->toBeTrue();

    $manifest = json_decode(Storage::disk('local')->get($backup->path.'/manifest.json'), true);
    expect(collect($manifest['archives'])->pluck('files', 'directory')->all())
        ->toBe(['invoices' => 1, 'statements' => 2, 'receipts' => 1]);

    $this->artisan('ernte:backup:verify', ['path' => $backup->path])->assertExitCode(0);
});

test('an empty receipts directory does not break the backup', function () {
    Storage::fake('local');
    Storage::disk('local')->makeDirectory('receipts');
    Process::fake(fn () => Process::result('SQL DUMP'));

    $this->artisan('ernte:backup')->assertExitCode(0);

    Storage::disk('local')->assertMissing(Backup::first()->path.'/receipts.tar.gz');
});

test('verification fails when an archive does not hold what the manifest says', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/abc.xml', '<Document/>');
    Process::fake(fn () => Process::result('SQL DUMP'));
    $this->artisan('ernte:backup')->assertExitCode(0);
    $backup = Backup::first();

    $manifestPath = $backup->path.'/manifest.json';
    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);
    $manifest['archives'][0]['files'] = 7;
    Storage::disk('local')->put($manifestPath, json_encode($manifest));

    $this->artisan('ernte:backup:verify', ['path' => $backup->path])
        ->expectsOutputToContain('holds 1 file(s), the manifest says 7')
        ->assertExitCode(1);
});

test('the scheduled backup has a failure hook that logs an error and mails the owner', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'ernte:backup'));
    expect((new ReflectionProperty($event, 'afterCallbacks'))->getValue($event))->not->toBeEmpty();

    Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()
        ->withArgs(fn (string $text) => str_contains($text, 'scheduled backup (ernte:backup) failed'));
    Illuminate\Support\Facades\Log::spy();
    config(['mail.default' => 'smtp']);
    App\Models\User::factory()->create(['email' => 'owner@example.com']);

    app(App\Services\Backups\BackupFailureAlert::class)();

    Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
});

test('without a real mailer a failed backup is still logged', function () {
    Illuminate\Support\Facades\Log::spy();
    config(['mail.default' => 'log']);
    Illuminate\Support\Facades\Mail::shouldReceive('raw')->never();
    App\Models\User::factory()->create(['email' => 'owner@example.com']);

    app(App\Services\Backups\BackupFailureAlert::class)();

    Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
});
