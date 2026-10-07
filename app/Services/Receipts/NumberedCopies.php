<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Models\StandingDocument;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\StatementImporter;
use App\Services\Banking\StatementPositions;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxException;
use App\Services\Dropbox\DropboxNotFound;
use App\Services\Invoicing\InvoicePdfRenderer;
use Illuminate\Support\Collection;

/**
 * Numbered files that ernte creates itself rather than renames: the monthly copy of a
 * standing document (the rent contract) and the PDF of a paid ernte invoice.
 */
class NumberedCopies
{
    public function __construct(
        private DropboxClient $dropbox,
        private ReceiptNumberer $numberer,
        private InvoicePdfRenderer $invoices,
    ) {}

    /** The standing document that pays this row, if the row still lacks a receipt. */
    public function standingFor(StatementLine $row, ?Collection $documents = null): ?StandingDocument
    {
        if ($row->source !== StatementImporter::SOURCE || $row->is_credit || $row->is_fee || $row->no_receipt || $row->is_reversal) {
            return null;
        }
        if ($row->receipts()->exists()) {
            return null;
        }

        return ($documents ?? StandingDocument::where('active', true)->get())->first(fn (StandingDocument $d) => $d->fits($row));
    }

    /** Whether this credit's invoice still has to go into the folder. */
    public function invoiceToFile(StatementLine $row): bool
    {
        return $row->source === StatementImporter::SOURCE && $row->is_credit && $row->match_state === 'matched'
            && $row->invoice !== null && $row->invoice->dropbox_file_id === null;
    }

    /**
     * Create the numbered file for one row: a standing-document copy or an invoice PDF.
     *
     * @return string what was created
     *
     * @throws \DomainException when nothing applies or the month is not ready
     */
    public function create(StatementLine $row): string
    {
        if ($this->invoiceToFile($row)) {
            return $this->fileInvoice($row);
        }
        if ($document = $this->standingFor($row)) {
            return $this->copyStanding($row, $document);
        }

        throw new \DomainException('There is nothing for ernte to create for this row.');
    }

    /**
     * @return array{numbered: int, skipped: list<string>}
     */
    public function numberMonth(int $year, int $month): array
    {
        $result = ['numbered' => 0, 'skipped' => []];
        $documents = StandingDocument::where('active', true)->get();

        $rows = StatementLine::where('source', StatementImporter::SOURCE)
            ->whereYear('booked_on', $year)->whereMonth('booked_on', $month)
            ->with('invoice')->orderBy('booked_on')->orderBy('id')->get();

        foreach ($rows as $row) {
            $standing = $this->standingFor($row, $documents);
            if (! $standing && ! $this->invoiceToFile($row)) {
                continue;
            }
            try {
                $standing ? $this->copyStanding($row, $standing) : $this->fileInvoice($row);
                $result['numbered']++;
            } catch (\DomainException|DropboxException $e) {
                $result['skipped'][] = ($standing?->filename ?? $row->invoice->pdfFilename()).': '.$e->getMessage();
            }
        }

        return $result;
    }

    private function copyStanding(StatementLine $row, StandingDocument $document): string
    {
        [$folder, $number] = $this->numberer->place($row);
        $name = StatementPositions::prefix($number).'_'.ReceiptPaths::sanitise($document->filename);
        $source = $this->dropbox->root().'/'.ltrim($document->source_path, '/');

        $this->numberer->ensureFolder($folder);
        try {
            $entry = $this->dropbox->copy($source, "{$folder}/{$name}");
        } catch (DropboxConflict) {
            throw new \DomainException("A file named \"{$name}\" already exists in its folder.");
        } catch (DropboxNotFound) {
            throw new \DomainException("The standing document {$document->source_path} was not found in Dropbox.");
        }

        Receipt::create([
            'source' => 'standing',
            'original_name' => $document->filename,
            'filename' => $entry['name'],
            // Not the file's own hash: every month's copy is the same document on purpose.
            'content_hash' => hash('sha256', "standing:{$document->id}:{$row->id}"),
            'original_mime' => 'application/pdf',
            'size_bytes' => 0,
            'extraction_status' => 'done',
            'vendor' => $document->label,
            'filing_status' => 'filed',
            'dropbox_file_id' => $entry['id'],
            'dropbox_path' => $entry['path'],
            'filed_at' => now(),
            'target_year' => $row->booked_on->year,
            'target_month' => $row->booked_on->month,
            'target_edited' => true,
            'statement_line_id' => $row->id,
            'match_state' => 'matched',
            'match_method' => 'standing',
            'numbered_at' => now(),
        ]);

        return $entry['name'];
    }

    private function fileInvoice(StatementLine $row): string
    {
        [$folder, $number] = $this->numberer->place($row);
        $invoice = $row->invoice;
        $name = StatementPositions::prefix($number).'_'.$invoice->pdfFilename();

        $this->numberer->ensureFolder($folder);
        try {
            $entry = $this->dropbox->upload("{$folder}/{$name}", $this->invoices->pdfBytes($invoice));
        } catch (DropboxConflict) {
            throw new \DomainException("A file named \"{$name}\" already exists in its folder.");
        }

        $invoice->update(['dropbox_file_id' => $entry['id'], 'dropbox_path' => $entry['path']]);

        return $entry['name'];
    }
}
