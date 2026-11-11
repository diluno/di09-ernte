<?php

namespace App\Services\Backups;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

class BackupVerifier
{
    /**
     * Verify that a backup's manifest, compressed SQL dump, and archives can be read.
     *
     * @return array{files:int, size_bytes:int}
     */
    public function verify(FilesystemAdapter $disk, string $directory): array
    {
        $manifestPath = "{$directory}/manifest.json";
        if (! $disk->exists($manifestPath)) {
            throw new RuntimeException("Missing backup manifest: {$manifestPath}");
        }

        try {
            $manifest = json_decode($disk->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Backup manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest['files'] ?? null) || $manifest['files'] === []) {
            throw new RuntimeException('Backup manifest contains no files.');
        }

        $total = 0;
        foreach ($manifest['files'] as $file) {
            $path = $file['path'] ?? null;
            $expectedSize = $file['size'] ?? null;
            if (! is_string($path) || ! is_int($expectedSize) || ! $disk->exists($path)) {
                throw new RuntimeException('Backup manifest references a missing or invalid file.');
            }

            $actualSize = $disk->size($path);
            if ($actualSize !== $expectedSize) {
                throw new RuntimeException("Backup file size mismatch: {$path}");
            }

            if (str_ends_with($path, 'database.sql.gz')) {
                $sql = gzdecode($disk->get($path));
                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException('Database dump is empty or not valid gzip data.');
                }
            }

            if (str_ends_with($path, '.tar.gz')) {
                try {
                    new \PharData($disk->path($path));
                } catch (\Exception $exception) {
                    throw new RuntimeException("Document archive cannot be opened: {$path}", previous: $exception);
                }
            }

            $total += $actualSize;
        }

        // Backups made before the manifest listed its archives have no such section.
        $listed = array_column($manifest['files'], 'path');
        foreach ($manifest['archives'] ?? [] as $archive) {
            $path = $archive['path'] ?? null;
            $expected = $archive['files'] ?? null;
            $directory = $archive['directory'] ?? '?';
            if (! is_string($path) || ! is_int($expected) || ! in_array($path, $listed, true)) {
                throw new RuntimeException("Backup manifest lists an archive for \"{$directory}\" that is not among its files.");
            }
            $actual = $this->countFiles($disk->path($path), "{$directory}/");
            if ($actual !== $expected) {
                throw new RuntimeException("Archive of \"{$directory}\" holds {$actual} file(s), the manifest says {$expected}: {$path}");
            }
        }

        return ['files' => count($manifest['files']), 'size_bytes' => $total];
    }

    /** Files in the archive that sit under the directory it claims to hold. */
    private function countFiles(string $archivePath, string $prefix): int
    {
        try {
            $count = 0;
            // The path inside the archive comes from the iterator, not from cutting the archive's
            // own path off the front: PHP resolves symlinks in that path (a Forge release's
            // storage directory is one), so the two would not line up.
            $files = new \RecursiveIteratorIterator(new \PharData($archivePath));
            foreach ($files as $file) {
                $inside = str_replace('\\', '/', $files->getSubPathname());
                if ($file->isFile() && str_starts_with($inside, $prefix)) {
                    $count++;
                }
            }

            return $count;
        } catch (\Exception $exception) {
            throw new RuntimeException("Document archive cannot be read: {$archivePath}", previous: $exception);
        }
    }
}
