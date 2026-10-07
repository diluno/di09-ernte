<?php

namespace App\Console\Commands;

use App\Services\Dropbox\DropboxClient;
use App\Services\Receipts\DropboxIntake;
use Illuminate\Console\Command;

class CheckReceiptInboxCommand extends Command
{
    protected $signature = 'ernte:receipts:check-inbox';

    protected $description = 'Pick up new PDFs from the Dropbox inbox folder and queue them for reading and sorting.';

    public function handle(DropboxClient $dropbox, DropboxIntake $intake): int
    {
        if (! $dropbox->isConnected()) {
            $this->line('Dropbox is not connected; nothing to check.');

            return self::SUCCESS;
        }

        $result = $intake->scanInbox();
        $this->info("Inbox: {$result['new']} new, {$result['duplicates']} duplicate(s), {$result['skipped']} non-PDF file(s) skipped, {$result['resorted']} put back and sorted again.");

        return self::SUCCESS;
    }
}
