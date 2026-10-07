<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxNotFound;
use Illuminate\Support\Facades\Storage;

/** Puts receipts into the bookkeeping folder in Dropbox and keeps ernte's record in step. */
class ReceiptFiler
{
    private const MAX_SUFFIX = 9;

    public const DUPLICATE_PREFIX = 'DUPLICATE-';

    public function __construct(private DropboxClient $dropbox) {}

    /**
     * File a receipt that is not in Dropbox yet. Returns false when Dropbox is not
     * connected (the receipt simply keeps waiting). Network and API failures throw, so
     * the calling job can retry.
     */
    public function file(Receipt $receipt): bool
    {
        if ($receipt->filing_status === 'filed') {
            return true;
        }
        if (! $this->dropbox->isConnected()) {
            $receipt->update(['filing_error' => 'Dropbox is not connected.']);

            return false;
        }
        if ($original = $receipt->likelyDuplicateOf()) {
            $this->markDuplicate($receipt, $original, exact: false);

            return false;
        }
        if ($receipt->dropbox_file_id) {
            return $this->sortFromInbox($receipt);
        }
        if (! $receipt->local_path || ! Storage::disk('local')->exists($receipt->local_path)) {
            $receipt->update(['filing_status' => 'failed', 'filing_error' => 'The uploaded file is no longer on the server; upload it again.']);

            return false;
        }

        [$year, $month] = $this->target($receipt);
        $root = $this->dropbox->root();
        $this->dropbox->createFolder(ReceiptPaths::quarterFolder($root, $year, $month));
        $folder = ReceiptPaths::monthFolder($root, $year, $month);
        $this->dropbox->createFolder($folder);

        $name = ReceiptPaths::initialName($receipt);
        // Only a name ernte made up may be varied; a name Sam or the vendor chose is kept or refused.
        $generated = ReceiptPaths::isGenerated($receipt);
        $contents = Storage::disk('local')->get($receipt->local_path);

        $entry = null;
        for ($attempt = 1; $entry === null; $attempt++) {
            $candidate = $attempt === 1 ? $name : ReceiptPaths::withSuffix($name, $attempt);
            try {
                $entry = $this->dropbox->upload("{$folder}/{$candidate}", $contents);
            } catch (DropboxConflict) {
                if (! $generated || $attempt >= self::MAX_SUFFIX) {
                    $receipt->update([
                        'filing_status' => 'failed', 'target_year' => $year, 'target_month' => $month,
                        'filing_error' => "A file named \"{$candidate}\" already exists in ".ReceiptPaths::label($year, $month).'. Rename this receipt and retry.',
                    ]);

                    return false;
                }
            }
        }

        $localPath = $receipt->local_path;
        $receipt->update([
            'filing_status' => 'filed', 'filing_error' => null,
            'dropbox_file_id' => $entry['id'], 'dropbox_path' => $entry['path'], 'filename' => $entry['name'],
            'target_year' => $year, 'target_month' => $month,
            'filed_at' => now(), 'local_path' => null,
        ]);
        // Dropbox is the store from here on; the local copy goes only once Dropbox confirmed.
        Storage::disk('local')->delete($localPath);

        return true;
    }

    /**
     * Record a receipt as a duplicate of one ernte already has. A file waiting in the
     * inbox is renamed "DUPLICATE-…" there, so it is easy to spot and delete by hand;
     * ernte itself never deletes.
     */
    public function markDuplicate(Receipt $receipt, Receipt $original, bool $exact): void
    {
        $what = $exact ? 'The same file as' : 'Reads like the same document as';
        $where = $original->dropbox_path ? ' in '.ReceiptPaths::label($original->target_year, $original->target_month) : '';
        $message = "{$what} \"".($original->filename ?? $original->original_name)."\"{$where}.";

        if ($receipt->dropbox_file_id) {
            try {
                $current = $this->dropbox->metadata($receipt->dropbox_file_id);
                $name = $current['name'];
                if (! str_starts_with($name, self::DUPLICATE_PREFIX)) {
                    $folder = dirname($current['path']);
                    for ($attempt = 1; $attempt <= self::MAX_SUFFIX; $attempt++) {
                        $candidate = self::DUPLICATE_PREFIX.($attempt === 1 ? $name : ReceiptPaths::withSuffix(ReceiptPaths::sanitise($name), $attempt));
                        try {
                            $current = $this->dropbox->move($receipt->dropbox_file_id, "{$folder}/{$candidate}");
                            break;
                        } catch (DropboxConflict) {
                            continue;
                        }
                    }
                }
                $receipt->dropbox_path = $current['path'];
                $message .= " Renamed to \"{$current['name']}\" in the inbox: delete it there.";
            } catch (\App\Services\Dropbox\DropboxException) {
                $message .= ' Delete it from the inbox in Dropbox.';
            }
        } else {
            $message .= ' Remove this receipt.';
        }

        $receipt->fill([
            'duplicate_of_id' => $original->id,
            'filing_status' => 'failed',
            'filing_error' => mb_substr($message, 0, 250),
        ])->save();
    }

    /** Sam says it is a separate document after all: undo the marking and file it. */
    public function notDuplicate(Receipt $receipt): void
    {
        $receipt->update([
            'duplicate_of_id' => null, 'not_duplicate' => true, 'filing_error' => null,
            'filing_status' => $receipt->dropbox_file_id ? 'inbox' : 'pending',
            'target_edited' => $receipt->target_edited || ($receipt->target_year !== null),
        ]);
    }

    /**
     * Move a file that arrived in the Dropbox inbox into its month folder. It only moves
     * once ernte knows which month: a receipt it could not date stays in the inbox.
     */
    private function sortFromInbox(Receipt $receipt): bool
    {
        if ($receipt->duplicate_of_id) {
            return false;
        }

        try {
            $current = $this->dropbox->metadata($receipt->dropbox_file_id);
        } catch (DropboxNotFound) {
            $receipt->update(['filing_status' => 'missing', 'filing_error' => 'The file is no longer in Dropbox.']);

            return false;
        }

        $dated = $receipt->target_edited || ($receipt->extraction_status === 'done' && $receipt->document_date !== null);
        if (! $dated || ! $receipt->target_year || ! $receipt->target_month) {
            $receipt->update(['filing_status' => 'failed', 'dropbox_path' => $current['path'],
                'filing_error' => 'No date could be read, so the file is still in the inbox. Set the month and file it.']);

            return false;
        }

        [$year, $month] = [$receipt->target_year, $receipt->target_month];
        $root = $this->dropbox->root();
        $this->dropbox->createFolder(ReceiptPaths::quarterFolder($root, $year, $month));
        $folder = ReceiptPaths::monthFolder($root, $year, $month);
        $this->dropbox->createFolder($folder);

        $name = ReceiptPaths::initialName($receipt);
        $generated = ReceiptPaths::isGenerated($receipt);

        $entry = null;
        for ($attempt = 1; $entry === null; $attempt++) {
            $candidate = $attempt === 1 ? $name : ReceiptPaths::withSuffix($name, $attempt);
            try {
                $entry = $this->dropbox->move($receipt->dropbox_file_id, "{$folder}/{$candidate}");
            } catch (DropboxConflict) {
                if (! $generated || $attempt >= self::MAX_SUFFIX) {
                    $receipt->update(['filing_status' => 'failed', 'dropbox_path' => $current['path'],
                        'filing_error' => "A file named \"{$candidate}\" already exists in ".ReceiptPaths::label($year, $month).'. It is still in the inbox; rename this receipt and file it again.']);

                    return false;
                }
            }
        }

        $localPath = $receipt->local_path;
        $receipt->update([
            'filing_status' => 'filed', 'filing_error' => null,
            'dropbox_path' => $entry['path'], 'filename' => $entry['name'],
            'filed_at' => now(), 'local_path' => null,
        ]);
        if ($localPath) {
            Storage::disk('local')->delete($localPath);
        }

        return true;
    }

    /**
     * Look the file up by ID and record where it is and what it is called now — Sam, the
     * accountant or the numbering scripts may have moved or renamed it.
     */
    public function refresh(Receipt $receipt): Receipt
    {
        if (! $receipt->dropbox_file_id) {
            return $receipt;
        }

        try {
            $entry = $this->dropbox->metadata($receipt->dropbox_file_id);
            // A file still waiting in the inbox keeps its state; only its location is noted.
            $receipt->update($receipt->filing_status === 'filed' || $receipt->filing_status === 'missing'
                ? ['filing_status' => 'filed', 'filing_error' => null, 'dropbox_path' => $entry['path'], 'filename' => $entry['name']]
                : ['dropbox_path' => $entry['path']]);
        } catch (DropboxNotFound) {
            $receipt->update(['filing_status' => 'missing', 'filing_error' => 'The file is no longer in Dropbox.']);
        }

        return $receipt;
    }

    /**
     * Move a filed receipt to another month and/or rename it.
     *
     * @throws \DomainException with a message for the user when that is not possible
     */
    public function relocate(Receipt $receipt, int $year, int $month, ?string $newName = null): void
    {
        $this->refresh($receipt);
        if ($receipt->filing_status === 'missing') {
            throw new \DomainException('The file is no longer in Dropbox, so it cannot be moved.');
        }
        if ($receipt->numberPrefix() !== null) {
            throw new \DomainException("This receipt is already numbered ({$receipt->numberPrefix()}) in Dropbox; ernte leaves numbered files where they are.");
        }

        $root = $this->dropbox->root();
        $this->dropbox->createFolder(ReceiptPaths::quarterFolder($root, $year, $month));
        $folder = ReceiptPaths::monthFolder($root, $year, $month);
        $this->dropbox->createFolder($folder);

        $name = $newName !== null ? ReceiptPaths::sanitise($newName) : $receipt->filename;
        $target = "{$folder}/{$name}";
        if (mb_strtolower($target) !== mb_strtolower((string) $receipt->dropbox_path)) {
            try {
                $entry = $this->dropbox->move($receipt->dropbox_file_id, $target);
            } catch (DropboxConflict) {
                throw new \DomainException("A file named \"{$name}\" already exists in ".ReceiptPaths::label($year, $month).'.');
            }
            $receipt->update(['dropbox_path' => $entry['path'], 'filename' => $entry['name']]);
        }

        $receipt->update(['target_year' => $year, 'target_month' => $month]);
    }

    /** The parking month: the receipt's own, else the month it was uploaded (Zurich time). */
    public function target(Receipt $receipt): array
    {
        if ($receipt->target_year && $receipt->target_month) {
            return [$receipt->target_year, $receipt->target_month];
        }
        $uploaded = $receipt->created_at->timezone('Europe/Zurich');

        return [(int) $uploaded->year, (int) $uploaded->month];
    }
}
