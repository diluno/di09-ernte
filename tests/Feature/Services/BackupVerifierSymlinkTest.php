<?php

use App\Services\Backups\BackupVerifier;
use Illuminate\Support\Facades\Storage;

// On Forge a release's storage directory is a symlink; PHP resolves it inside phar:// paths.
test('archive file counts are verified when the disk root is reached through a symlink', function () {
    $real = sys_get_temp_dir().'/ernte-backup-real-'.uniqid();
    $link = sys_get_temp_dir().'/ernte-backup-link-'.uniqid();
    mkdir($real.'/backups/b1', 0777, true);
    symlink($real, $link);

    try {
        $tarPath = $real.'/backups/b1/invoices.tar';
        $tar = new PharData($tarPath);
        $tar->addFromString('invoices/2026-001.pdf', 'one');
        $tar->addFromString('invoices/2026-002.pdf', 'two');
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($tarPath);

        $disk = Storage::build(['driver' => 'local', 'root' => $link]);
        $archive = 'backups/b1/invoices.tar.gz';
        $disk->put('backups/b1/manifest.json', json_encode([
            'files' => [['path' => $archive, 'size' => $disk->size($archive)]],
            'archives' => [['directory' => 'invoices', 'path' => $archive, 'files' => 2]],
        ]));

        expect(app(BackupVerifier::class)->verify($disk, 'backups/b1')['files'])->toBe(1);
    } finally {
        unlink($link);
        exec('rm -rf '.escapeshellarg($real));
    }
});
