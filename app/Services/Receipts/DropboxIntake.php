<?php

namespace App\Services\Receipts;

use App\Jobs\ExtractReceipt;
use App\Jobs\FileReceipt;
use App\Models\Receipt;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxNotFound;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Receipts that reach Dropbox without going through ernte's upload page: scans saved to
 * the inbox folder, and files that were sorted into month folders by hand.
 */
class DropboxIntake
{
    public function __construct(private DropboxClient $dropbox, private ReceiptFiler $filer) {}

    public function inboxPath(): string
    {
        return $this->dropbox->root().'/'.trim((string) config('services.dropbox.inbox_folder', '_Inbox'), '/');
    }

    /**
     * Register every new PDF in the inbox and queue it for reading and sorting.
     *
     * @return array{new: int, duplicates: int, skipped: int, resorted: int}
     */
    public function scanInbox(): array
    {
        $result = ['new' => 0, 'duplicates' => 0, 'skipped' => 0, 'resorted' => 0];
        $inbox = $this->inboxPath();

        try {
            $entries = $this->dropbox->listFolder($inbox);
        } catch (DropboxNotFound) {
            $this->dropbox->createFolder($inbox);

            return $result;
        }

        $present = [];
        foreach ($entries as $entry) {
            if ($entry['is_folder'] || ! $entry['id']) {
                continue;
            }
            $present[] = $entry['id'];
            if (! preg_match('/\.pdf$/i', $entry['name'])) {
                // The folder only ever holds PDFs; ernte cannot replace a photo with its PDF without deleting.
                $result['skipped']++;

                continue;
            }
            if ($known = Receipt::where('dropbox_file_id', $entry['id'])->first()) {
                // A filed receipt that was put back into the inbox is sorted again, the way
                // a new one would be. A numbered file is not ernte's to move: it is left.
                if ($known->filing_status === 'filed' && $known->duplicate_of_id === null && $known->numbered_at === null && $known->numberPrefix() === null) {
                    $known->update(['filing_status' => 'inbox', 'filing_error' => null, 'dropbox_path' => $entry['path'], 'filename' => null, 'original_name' => mb_substr($entry['name'], 0, 255)]);
                    FileReceipt::dispatch($known->id);
                    $result['resorted']++;
                }

                continue;
            }

            $bytes = $this->dropbox->download($entry['id']);
            $hash = hash('sha256', $bytes);
            $original = Receipt::where('content_hash', $hash)->whereNull('duplicate_of_id')->first();

            $receipt = Receipt::create([
                'source' => 'inbox',
                'original_name' => mb_substr($entry['name'], 0, 255),
                'content_hash' => $hash,
                'original_mime' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'dropbox_file_id' => $entry['id'],
                'dropbox_path' => $entry['path'],
                'filing_status' => 'inbox',
            ]);

            if ($original) {
                $receipt->update(['extraction_status' => 'done', 'vendor' => $original->vendor, 'vendor_domain' => $original->vendor_domain]);
                $this->filer->markDuplicate($receipt, $original, exact: true);
                $result['duplicates']++;

                continue;
            }

            $path = "receipts/{$hash}.pdf";
            Storage::disk('local')->put($path, $bytes);
            $receipt->update(['local_path' => $path]);
            Bus::chain([new ExtractReceipt($receipt->id), new FileReceipt($receipt->id)])->dispatch();
            $result['new']++;
        }

        // A duplicate that was since deleted from the inbox needs no record any more.
        Receipt::where('source', 'inbox')->whereNotNull('duplicate_of_id')
            ->whereNotIn('dropbox_file_id', $present ?: [''])->delete();

        return $result;
    }

    /**
     * Files already sitting in a quarter's month folders (and their Kreditkarte subfolders)
     * that ernte does not know yet. Statements (leading underscore) are not receipts.
     * $except holds filename patterns to leave out, e.g. "*Lohnabrechnung*".
     *
     * @return list<array{id: string, name: string, path: string, year: int, month: int}>
     */
    public function unknownInQuarter(int $year, int $quarter, array $except = []): array
    {
        $found = [];
        $excluded = fn (string $name) => collect($except)->contains(fn (string $pattern) => fnmatch(mb_strtolower($pattern), mb_strtolower($name)));
        foreach (range(($quarter - 1) * 3 + 1, $quarter * 3) as $month) {
            $folder = ReceiptPaths::monthFolder($this->dropbox->root(), $year, $month);
            foreach ([$folder, "{$folder}/Kreditkarte"] as $path) {
                try {
                    $entries = $this->dropbox->listFolder($path);
                } catch (DropboxNotFound) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($entry['is_folder'] || ! $entry['id'] || str_starts_with($entry['name'], '_')
                        || ! preg_match('/\.pdf$/i', $entry['name'])
                        || $excluded($entry['name'])
                        || Receipt::where('dropbox_file_id', $entry['id'])->exists()) {
                        continue;
                    }
                    $found[] = ['id' => $entry['id'], 'name' => $entry['name'], 'path' => $entry['path'], 'year' => $year, 'month' => $month];
                }
            }
        }

        return $found;
    }

    /** Register one existing file where it is and queue it for reading. It is never moved. */
    public function adopt(array $file): Receipt
    {
        $bytes = $this->dropbox->download($file['id']);
        $hash = hash('sha256', $bytes);
        $path = "receipts/{$hash}.pdf";
        Storage::disk('local')->put($path, $bytes);

        $receipt = Receipt::create([
            'source' => 'existing',
            'original_name' => mb_substr($file['name'], 0, 255),
            'filename' => mb_substr($file['name'], 0, 255),
            'content_hash' => $hash,
            'original_mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'local_path' => $path,
            'dropbox_file_id' => $file['id'],
            'dropbox_path' => $file['path'],
            'filing_status' => 'filed',
            'filed_at' => now(),
            'target_year' => $file['year'],
            'target_month' => $file['month'],
            'target_edited' => true,
        ]);
        ExtractReceipt::dispatch($receipt->id);

        return $receipt;
    }
}
