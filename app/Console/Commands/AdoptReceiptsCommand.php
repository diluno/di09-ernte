<?php

namespace App\Console\Commands;

use App\Services\Receipts\DropboxIntake;
use Illuminate\Console\Command;

class AdoptReceiptsCommand extends Command
{
    protected $signature = 'ernte:receipts:adopt {year} {quarter}
        {--except=* : Filename pattern to leave out, e.g. --except="*Lohnabrechnung*" (repeatable; number prefixes are part of the name)}
        {--dry-run : Only list the files}';

    protected $description = 'Register receipts that already sit in a quarter\'s Dropbox folders. Files are read with Claude and never moved.';

    public function handle(DropboxIntake $intake): int
    {
        $year = (int) $this->argument('year');
        $quarter = (int) $this->argument('quarter');
        if ($quarter < 1 || $quarter > 4) {
            $this->error('Quarter must be 1–4.');

            return self::FAILURE;
        }

        $except = array_values(array_filter((array) $this->option('except')));
        $files = $intake->unknownInQuarter($year, $quarter, $except);
        if ($except) {
            $this->line('Leaving out: '.implode(', ', $except));
        }
        foreach ($files as $file) {
            $this->line($file['path']);
        }
        $count = count($files);
        $this->info("{$count} file(s) in {$year} Q{$quarter} are not known to ernte.");

        if ($count === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->confirm("Register these {$count} file(s)? Each one is sent to Claude to be read.")) {
            return self::SUCCESS;
        }

        $this->withProgressBar($files, fn (array $file) => $intake->adopt($file));
        $this->newLine();
        $this->info('Registered. Reading runs in the queue.');

        return self::SUCCESS;
    }
}
