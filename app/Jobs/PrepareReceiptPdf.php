<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Services\Receipts\ReceiptImageConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Photographed receipts become a one-page PDF. PDFs pass through untouched. */
class PrepareReceiptPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 100;

    public function __construct(public int $receiptId) {}

    public function handle(ReceiptImageConverter $converter): void
    {
        $receipt = Receipt::find($this->receiptId);
        $disk = Storage::disk('local');
        if (! $receipt || ! $receipt->isPhoto() || ! $receipt->local_path
            || str_ends_with($receipt->local_path, '.pdf') || ! $disk->exists($receipt->local_path)) {
            return;
        }

        try {
            $pdf = $converter->toPdf($disk->get($receipt->local_path));
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
            // Leave the image in place; extraction and filing will report the receipt as unreadable.
            $receipt->update(['extraction_status' => 'failed', 'extraction_error' => 'The image could not be converted to PDF.']);

            return;
        }

        $image = $receipt->local_path;
        $path = "receipts/{$receipt->content_hash}.pdf";
        $disk->put($path, $pdf);
        $receipt->update(['local_path' => $path]);
        $disk->delete($image);
    }
}
