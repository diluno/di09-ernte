<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class VerifyBackupCommand extends Command
{
    protected $signature = 'ernte:backup:verify {path? : Backup directory; defaults to the latest recorded backup}';

    protected $description = 'Verify that a backup manifest, SQL dump, and document archives are readable.';

    public function handle(BackupVerifier $verifier): int
    {
        $path = $this->argument('path') ?: Backup::latest()?->path;
        if (! $path) {
            $this->error('No backup path was supplied and no recorded backup exists.');

            return self::FAILURE;
        }

        try {
            $result = $verifier->verify(Storage::disk('local'), $path);
        } catch (\Throwable $exception) {
            $this->error("Backup verification failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup verified at {$path} ({$result['files']} artifact(s), {$result['size_bytes']} bytes).");

        return self::SUCCESS;
    }
}
