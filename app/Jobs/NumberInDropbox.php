<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Models\StatementLine;
use App\Services\Dropbox\DropboxException;
use App\Services\Receipts\NumberedCopies;
use App\Services\Receipts\NumberingProgress;
use App\Services\Receipts\ReceiptNumberer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Numbers one file in Dropbox: a matched receipt, or the copy ernte creates for a row
 * (rent contract, invoice PDF). One job per file, so a month of forty files is forty short
 * jobs instead of one web request that runs into the server's time limit.
 */
class NumberInDropbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 100;

    public int $backoff = 10;

    public function __construct(public string $month, public ?int $receiptId = null, public ?int $lineId = null) {}

    public function handle(ReceiptNumberer $numberer, NumberedCopies $copies, NumberingProgress $progress): void
    {
        $label = 'file';
        try {
            if ($this->receiptId) {
                $receipt = Receipt::with('statementLine')->find($this->receiptId);
                if ($receipt) {
                    $label = $receipt->filename ?? $receipt->original_name;
                    $numberer->number($receipt);
                }
            } elseif ($line = StatementLine::with('invoice')->find($this->lineId)) {
                $label = $line->invoice?->pdfFilename() ?? ($line->counterparty_name ?? 'row');
                $copies->create($line);
            }
            $progress->advance($this->month);
        } catch (\DomainException $e) {
            $progress->advance($this->month, "{$label}: {$e->getMessage()}");
        } catch (DropboxException $e) {
            // Dropbox hiccup: try once more before giving up on this file.
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
            $progress->advance($this->month, "{$label}: {$e->getMessage()}");
        }
    }

    public function failed(\Throwable $e): void
    {
        app(NumberingProgress::class)->advance($this->month, 'A file could not be numbered: '.mb_substr($e->getMessage(), 0, 160));
    }
}
