<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\StatementImporter;
use App\Services\Banking\StatementPositions;
use App\Services\Banking\VisecaImporter;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the accountant's numbering into Dropbox: a matched receipt is renamed to
 * "NN_<name>" in the month of its bank row, or moved to "Kreditkarte/NN_<name>" in the
 * month its card bill was paid. One file per call, one Dropbox move per file.
 */
class ReceiptNumberer
{
    public function __construct(
        private DropboxClient $dropbox,
        private ReceiptFiler $filer,
        private StatementPositions $positions,
    ) {}

    /**
     * @throws \DomainException with the reason, when this receipt cannot be numbered (yet)
     * @throws DropboxException when Dropbox cannot be reached
     */
    public function number(Receipt $receipt): void
    {
        if ($receipt->numbered_at !== null) {
            return;
        }
        $row = $receipt->statementLine;
        if ($receipt->match_state !== 'matched' || ! $row) {
            throw new \DomainException('Confirm which row paid this receipt first.');
        }
        if (! $receipt->dropbox_file_id || $receipt->filing_status !== 'filed') {
            throw new \DomainException('The receipt is not filed in Dropbox yet.');
        }

        [$folder, $number] = $this->place($row);

        $this->filer->refresh($receipt);
        if ($receipt->filing_status === 'missing') {
            throw new \DomainException('The file is no longer in Dropbox.');
        }

        $prefix = StatementPositions::prefix($number);
        $current = (string) $receipt->dropbox_path;

        // Already numbered by the scripts or by hand: accept the same number, refuse another.
        if (($existing = $receipt->numberPrefix()) !== null) {
            if ((int) $existing !== $number) {
                throw new \DomainException("The file is already numbered {$existing} in Dropbox, but its row is number {$prefix}.");
            }
            $receipt->update(['numbered_at' => now(), 'numbered_from_path' => null]);

            return;
        }

        $name = "{$prefix}_{$receipt->filename}";
        $this->ensureFolder($folder);
        try {
            $entry = $this->dropbox->move($receipt->dropbox_file_id, "{$folder}/{$name}");
        } catch (DropboxConflict) {
            throw new \DomainException("A file named \"{$name}\" already exists in its folder.");
        }

        [$year, $month] = $this->monthOf($row);
        $receipt->update([
            'filename' => $entry['name'], 'dropbox_path' => $entry['path'],
            'numbered_at' => now(), 'numbered_from_path' => $current,
            'target_year' => $year, 'target_month' => $month,
        ]);
    }

    /** Take the number off again and put the file back where it was. */
    public function undo(Receipt $receipt): string
    {
        if ($receipt->numbered_at === null) {
            throw new \DomainException('This receipt was not numbered by ernte.');
        }

        $this->filer->refresh($receipt);
        $row = $receipt->statementLine;
        $prefix = $row?->position !== null ? StatementPositions::prefix($row->position) : null;
        $from = $receipt->numbered_from_path;

        // Only move back a file that is still what ernte made it. Anything else was
        // changed by someone on purpose: ernte then merely forgets that it numbered it.
        if ($receipt->filing_status !== 'filed' || ! $from || $prefix === null || ! str_starts_with((string) $receipt->filename, "{$prefix}_")) {
            $receipt->update(['numbered_at' => null, 'numbered_from_path' => null]);

            return $from
                ? 'The file was changed in Dropbox since, so it was left as it is.'
                : 'ernte had not renamed this file, so it was left as it is.';
        }

        try {
            $entry = $this->dropbox->move($receipt->dropbox_file_id, $from);
        } catch (DropboxConflict) {
            throw new \DomainException('Its old place is taken by another file of the same name.');
        }

        $update = ['filename' => $entry['name'], 'dropbox_path' => $entry['path'], 'numbered_at' => null, 'numbered_from_path' => null];
        if (preg_match('#/(\d{4})_Q\d/(\d{2})/#', $entry['path'], $m)) {
            $update += ['target_year' => (int) $m[1], 'target_month' => (int) $m[2]];
        }
        $receipt->update($update);

        return 'Number removed; the file is back in its month folder.';
    }

    /**
     * Number every confirmed, not yet numbered receipt of a month: its bank rows and the
     * card bills paid in it. A file that cannot be numbered is skipped; the rest carry on.
     *
     * @return array{numbered: int, skipped: list<string>}
     */
    public function numberMonth(int $year, int $month): array
    {
        $billIds = Statement::where('source', VisecaImporter::SOURCE)->whereNotNull('bank_line_id')
            ->whereHas('bankLine', fn ($q) => $q->whereYear('booked_on', $year)->whereMonth('booked_on', $month))->pluck('id');

        $receipts = Receipt::where('match_state', 'matched')->whereNull('numbered_at')
            ->whereHas('statementLine', fn ($q) => $q
                ->where(fn ($b) => $b->where('source', StatementImporter::SOURCE)->whereYear('booked_on', $year)->whereMonth('booked_on', $month))
                ->orWhereIn('statement_id', $billIds))
            ->with('statementLine')->orderBy('id')->get();

        $result = ['numbered' => 0, 'skipped' => []];
        foreach ($receipts as $receipt) {
            try {
                $this->number($receipt);
                $result['numbered']++;
            } catch (\DomainException|DropboxException $e) {
                $result['skipped'][] = ($receipt->filename ?? $receipt->original_name).': '.$e->getMessage();
            }
        }

        return $result;
    }

    /** Whether a row's number is final, and if not, why. Null means it can be used. */
    public function blocker(StatementLine $row): ?string
    {
        try {
            $this->place($row, freeze: false);

            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /**
     * The folder and number for a row. Numbers are frozen on first use and never change.
     *
     * @return array{0: string, 1: int}
     */
    public function place(StatementLine $row, bool $freeze = true): array
    {
        [$year, $month] = $this->monthOf($row);
        $folder = ReceiptPaths::monthFolder($this->dropbox->root(), $year, $month);

        if (! $this->dropbox->isConnected()) {
            throw new \DomainException('Dropbox is not connected.');
        }

        if ($row->source === VisecaImporter::SOURCE) {
            $bill = $row->statement;
            if ($row->is_credit) {
                throw new \DomainException('Credits on the card bill are not numbered.');
            }
            $lines = $this->positions->bill($bill);
            $folder .= '/Kreditkarte';
        } else {
            if (! $this->positions->bankMonthComplete($year, $month)) {
                throw new \DomainException(sprintf('The bank statements of %02d/%d are not complete yet, so its numbers are not final.', $month, $year));
            }
            $lines = $this->positions->bankMonth($year, $month);
        }

        if ($freeze && $lines->contains(fn (StatementLine $l) => $l->position === null && $l->number !== null)) {
            DB::transaction(fn () => $lines->each(fn (StatementLine $l) => $l->number !== null
                ? StatementLine::whereKey($l->id)->update(['position' => $l->number]) : null));
        }

        $number = $lines->firstWhere('id', $row->id)?->number;
        if ($number === null) {
            throw new \DomainException('This row has no number.');
        }
        if ($freeze) {
            $row->position = $number;
        }

        return [$folder, $number];
    }

    /** Bank rows belong to their booking month; card rows to the month their bill was paid. */
    private function monthOf(StatementLine $row): array
    {
        if ($row->source === VisecaImporter::SOURCE) {
            $debit = $row->statement?->bankLine;
            if (! $debit) {
                throw new \DomainException('This card charge is not on a bill yet; import the bank statement with the Viseca debit.');
            }

            return [(int) $debit->booked_on->year, (int) $debit->booked_on->month];
        }

        return [(int) $row->booked_on->year, (int) $row->booked_on->month];
    }

    public function ensureFolder(string $folder): void
    {
        $root = $this->dropbox->root();
        $path = $root;
        foreach (explode('/', trim(substr($folder, strlen($root)), '/')) as $segment) {
            $path .= "/{$segment}";
            $this->dropbox->createFolder($path);
        }
    }
}
