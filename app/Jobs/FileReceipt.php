<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Services\Receipts\ReceiptFiler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FileReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 100;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [20, 120];

    public function __construct(public int $receiptId) {}

    public function handle(ReceiptFiler $filer): void
    {
        $receipt = Receipt::find($this->receiptId);
        if (! $receipt) {
            return;
        }

        try {
            $filer->file($receipt);
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
            $receipt->update(['filing_status' => 'failed', 'filing_error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    /** Reached on a timeout; handle() itself marks every other failure. */
    public function failed(?\Throwable $e): void
    {
        $receipt = Receipt::find($this->receiptId);
        if ($receipt && $receipt->filing_status !== 'filed') {
            $receipt->update(['filing_status' => 'failed', 'filing_error' => 'Filing in Dropbox did not finish in time. File it again.']);
        }
    }
}
