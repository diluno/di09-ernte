<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupVerifier;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupCommand extends Command
{
    protected $signature = 'ernte:backup';

    protected $description = 'Create, verify, mirror, and retain database and generated-document backups.';

    public function handle(BackupVerifier $verifier): int
    {
        $disk = Storage::disk('local');
        $stamp = now()->format('Ymd-His-u');
        $dir = "backups/{$stamp}";
        $disk->makeDirectory($dir);

        $dump = Process::timeout(120)->run($this->mysqldumpCommand());

        if (! $dump->successful()) {
            $disk->deleteDirectory($dir);
            $this->error('Database dump failed: '.trim($dump->errorOutput()));

            return self::FAILURE;
        }

        $dbPath = "{$dir}/database.sql.gz";
        $disk->put($dbPath, gzencode($dump->output(), 9));

        $files = [
            ['path' => $dbPath, 'size' => $disk->size($dbPath)],
        ];

        foreach (['invoices', 'estimates'] as $documentDirectory) {
            $archive = $this->archiveDirectory($documentDirectory, $dir);
            if ($archive) {
                $files[] = ['path' => $archive, 'size' => $disk->size($archive)];
            }
        }

        $manifestPath = "{$dir}/manifest.json";
        $manifest = [
            'app_version' => config('app.version', '0.1.0'),
            'created_at' => now()->toIso8601String(),
            'database' => [
                'connection' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
            ],
            'files' => $files,
        ];
        $disk->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        $files[] = ['path' => $manifestPath, 'size' => $disk->size($manifestPath)];

        try {
            $verifier->verify($disk, $dir);
            $this->mirror($disk, $files);
        } catch (\Throwable $exception) {
            $this->discardPartialMirror($dir);
            $this->error("Backup verification or mirroring failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $size = array_sum(array_column($files, 'size'));

        Backup::create([
            'path' => $dir,
            'size_bytes' => $size,
            'created_at' => now(),
        ]);

        $this->pruneExpiredBackups($disk);

        $this->info("Backup created at {$dir} ({$size} bytes).");

        return self::SUCCESS;
    }

    private function mysqldumpCommand(): array
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host='.($db['host'] ?? '127.0.0.1'),
            '--port='.($db['port'] ?? 3306),
            '--user='.($db['username'] ?? ''),
        ];

        if (($db['password'] ?? '') !== '') {
            $command[] = '--password='.$db['password'];
        }

        $command[] = $db['database'] ?? '';

        return $command;
    }

    private function archiveDirectory(string $documentDirectory, string $dir): ?string
    {
        $disk = Storage::disk('local');
        $source = $disk->path($documentDirectory);

        if (! is_dir($source)) {
            return null;
        }

        $archiveRelative = "{$dir}/{$documentDirectory}.tar.gz";
        $archiveAbsolute = $disk->path($archiveRelative);
        $tarAbsolute = substr($archiveAbsolute, 0, -3);

        if (file_exists($tarAbsolute)) {
            unlink($tarAbsolute);
        }
        if (file_exists($archiveAbsolute)) {
            unlink($archiveAbsolute);
        }

        $tar = new \PharData($tarAbsolute);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $tar->addFile($file->getPathname(), $documentDirectory.'/'.ltrim(str_replace($source, '', $file->getPathname()), DIRECTORY_SEPARATOR));
            }
        }

        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tarAbsolute);

        return $archiveRelative;
    }

    /** @param array<int, array{path:string, size:int}> $files */
    private function mirror(FilesystemAdapter $source, array $files): void
    {
        $mirrorDisk = config('backup.mirror_disk');
        if (! $mirrorDisk || $mirrorDisk === 'local') {
            return;
        }

        $mirror = Storage::disk($mirrorDisk);
        foreach ($files as $file) {
            $stream = $source->readStream($file['path']);
            if (! is_resource($stream)) {
                throw new \RuntimeException("Could not read backup artifact for mirroring: {$file['path']}");
            }

            try {
                if (! $mirror->writeStream($file['path'], $stream)) {
                    throw new \RuntimeException("Could not mirror backup artifact: {$file['path']}");
                }
            } finally {
                fclose($stream);
            }

            if (! $mirror->exists($file['path']) || $mirror->size($file['path']) !== $file['size']) {
                throw new \RuntimeException("Mirrored backup artifact failed verification: {$file['path']}");
            }
        }
    }

    private function pruneExpiredBackups(FilesystemAdapter $disk): void
    {
        $retentionDays = (int) config('backup.retention_days', 30);
        if ($retentionDays < 1) {
            return;
        }

        $mirrorDisk = config('backup.mirror_disk');
        $mirror = $mirrorDisk && $mirrorDisk !== 'local' ? Storage::disk($mirrorDisk) : null;

        Backup::query()
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->orderBy('created_at')
            ->get()
            ->each(function (Backup $backup) use ($disk, $mirror) {
                if (! str_starts_with($backup->path, 'backups/')) {
                    return;
                }

                $disk->deleteDirectory($backup->path);
                $mirror?->deleteDirectory($backup->path);
                $backup->delete();
            });
    }

    private function discardPartialMirror(string $directory): void
    {
        $mirrorDisk = config('backup.mirror_disk');
        if (! $mirrorDisk || $mirrorDisk === 'local') {
            return;
        }

        try {
            Storage::disk($mirrorDisk)->deleteDirectory($directory);
        } catch (\Throwable) {
            // Preserve the original verification/mirroring failure reported by handle().
        }
    }
}
