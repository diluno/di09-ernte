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

    public int $backoff = 20;

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
}
