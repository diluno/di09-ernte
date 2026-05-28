<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupCommand extends Command
{
    protected $signature = 'ernte:backup';

    protected $description = 'Create a database dump and invoice-file backup.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $stamp = now()->format('Ymd-His');
        $dir = "backups/{$stamp}";
        $disk->makeDirectory($dir);

        $dump = Process::timeout(120)->run($this->mysqldumpCommand());

        if (! $dump->successful()) {
            $disk->deleteDirectory($dir);
            $this->error('Database dump failed: ' . trim($dump->errorOutput()));

            return self::FAILURE;
        }

        $dbPath = "{$dir}/database.sql.gz";
        $disk->put($dbPath, gzencode($dump->output(), 9));

        $files = [
            ['path' => $dbPath, 'size' => $disk->size($dbPath)],
        ];

        $invoiceArchive = $this->archiveInvoices($dir);
        if ($invoiceArchive) {
            $files[] = ['path' => $invoiceArchive, 'size' => $disk->size($invoiceArchive)];
        }

        $manifestPath = "{$dir}/manifest.json";
        $manifest = [
            'app_version' => config('app.version', '0.1.0'),
            'created_at' => now()->toIso8601String(),
            'database' => [
                'connection' => config('database.default'),
                'database' => config('database.connections.' . config('database.default') . '.database'),
            ],
            'files' => $files,
        ];
        $disk->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        $files[] = ['path' => $manifestPath, 'size' => $disk->size($manifestPath)];

        $size = array_sum(array_column($files, 'size'));

        Backup::create([
            'path' => $dir,
            'size_bytes' => $size,
            'created_at' => now(),
        ]);

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
            '--host=' . ($db['host'] ?? '127.0.0.1'),
            '--port=' . ($db['port'] ?? 3306),
            '--user=' . ($db['username'] ?? ''),
        ];

        if (($db['password'] ?? '') !== '') {
            $command[] = '--password=' . $db['password'];
        }

        $command[] = $db['database'] ?? '';

        return $command;
    }

    private function archiveInvoices(string $dir): ?string
    {
        $disk = Storage::disk('local');
        $source = $disk->path('invoices');

        if (! is_dir($source)) {
            return null;
        }

        $archiveRelative = "{$dir}/invoices.tar.gz";
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
                $tar->addFile($file->getPathname(), 'invoices/' . ltrim(str_replace($source, '', $file->getPathname()), DIRECTORY_SEPARATOR));
            }
        }

        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tarAbsolute);

        return $archiveRelative;
    }
}
