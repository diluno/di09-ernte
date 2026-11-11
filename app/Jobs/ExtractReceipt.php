<?php

namespace App\Jobs;

use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Models\Receipt;
use App\Services\Dropbox\DropboxClient;
use App\Services\Receipts\PdfText;
use App\Services\Receipts\PermanentReceiptFailure;
use App\Services\Receipts\ReceiptExtractor;
use App\Services\Receipts\VendorLogos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Stores the PDF's text layer, then has Claude read the fields. A receipt that cannot be
 * read is marked and the chain carries on: it must still reach the bookkeeping folder.
 */
class ExtractReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 100;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [15, 90];

    public function __construct(public int $receiptId) {}

    public function handle(ReceiptExtractor $extractor, PdfText $pdfText, DropboxClient $dropbox, ?VendorLogos $logos = null): void
    {
        $receipt = Receipt::find($this->receiptId);
        if (! $receipt) {
            return;
        }
        $logos ??= app(VendorLogos::class);

        try {
            $pdf = $this->pdfBytes($receipt, $dropbox);
            $receipt->update(['text_layer' => $this->textLayer($pdf, $pdfText)]);
            $fields = $extractor->read($pdf);
        } catch (\Throwable $e) {
            if (! $this->isPermanent($e) && $this->attempts() < $this->tries) {
                throw $e;
            }
            $this->markFailed($receipt, $e->getMessage());

            return;
        }

        $update = ['extraction_status' => 'done', 'extraction_error' => null, 'extraction' => $fields];
        if (! $receipt->fields_edited) {
            $update += [
                'vendor' => $fields['vendor'],
                'vendor_domain' => $fields['vendor_domain'] ?? null,
                'document_date' => $fields['document_date'],
                'total_minor' => $fields['total'] !== null ? (int) round(((float) $fields['total']) * 100) : null,
                'currency' => $fields['currency'],
                'amounts' => $fields['amounts'],
                'invoice_number' => $fields['invoice_number'],
                'payment_method' => $fields['payment_method'],
                'confidence' => $fields['confidence'],
            ];
        }
        $date = $receipt->fields_edited ? $receipt->document_date?->toDateString() : $fields['document_date'];
        $receipt->update($update + $this->targetFrom($receipt, $date));

        // No website on this document: use the one already known for the vendor.
        if (! $receipt->vendor_domain && ($known = Receipt::domainForVendor($receipt->vendor))) {
            $receipt->update(['vendor_domain' => $known]);
        }
        if ($logos->wanted($receipt->vendor_domain)) {
            FetchVendorLogo::dispatch($receipt->vendor_domain);
        }

        // A file that already has its place in Dropbox needs no copy on the server.
        if ($receipt->filing_status === 'filed' && $receipt->local_path) {
            Storage::disk('local')->delete($receipt->local_path);
            $receipt->update(['local_path' => null]);
        }
    }

    /**
     * Reached when the worker gave up on the job itself, which in practice is a timeout:
     * handle() turns every other failure into a marked receipt. Without this the receipt
     * would stay "pending" for good, and the chain's filing step would never run.
     */
    public function failed(?\Throwable $e): void
    {
        $receipt = Receipt::find($this->receiptId);
        if (! $receipt) {
            return;
        }
        if ($receipt->extraction_status !== 'done') {
            $this->markFailed($receipt, 'Reading the receipt did not finish in time. Read it again.');
        }
        // A failed job ends its chain, but an unread receipt must still reach the folder.
        if (! empty($this->chained)) {
            FileReceipt::dispatch($receipt->id);
        }
    }

    private function markFailed(Receipt $receipt, string $message): void
    {
        $receipt->update([
            'extraction_status' => 'failed',
            'extraction_error' => mb_substr($message, 0, 250),
        ] + $this->targetFrom($receipt, null));
    }

    /** Errors another attempt would only repeat: configuration, refusals, requests the API rejects. */
    private function isPermanent(\Throwable $e): bool
    {
        return $e instanceof PermanentReceiptFailure
            || $e instanceof AuthenticationException
            || $e instanceof PermissionDeniedException
            || $e instanceof BadRequestException
            || $e instanceof NotFoundException
            || $e instanceof UnprocessableEntityException;
    }

    private function pdfBytes(Receipt $receipt, DropboxClient $dropbox): string
    {
        $disk = Storage::disk('local');
        if ($receipt->local_path && $disk->exists($receipt->local_path)) {
            if (! str_ends_with($receipt->local_path, '.pdf')) {
                throw new PermanentReceiptFailure('The image could not be converted to PDF.');
            }

            return $disk->get($receipt->local_path);
        }
        if ($receipt->dropbox_file_id) {
            return $dropbox->download($receipt->dropbox_file_id);
        }

        throw new PermanentReceiptFailure('The file is neither on the server nor in Dropbox.');
    }

    private function textLayer(string $pdf, PdfText $pdfText): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'receipt');
        try {
            file_put_contents($tmp, $pdf);

            return $pdfText->extract($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The parking month follows the receipt's own date; without one, the arrival date in
     * Zurich. A receipt that arrives after the month it is dated for (an invoice dated the
     * 30th, paid in the next month) is parked in the month it arrived, because the
     * accountant books it when it is paid. Receipts imported from the Dropbox folders keep
     * their own date: they were filed long before ernte saw them. A month Sam chose, or a
     * receipt already in Dropbox, is left alone.
     */
    private function targetFrom(Receipt $receipt, ?string $documentDate): array
    {
        if ($receipt->target_edited || $receipt->filing_status === 'filed') {
            return [];
        }
        $arrived = $receipt->created_at->timezone('Europe/Zurich')->startOfMonth();
        $date = $documentDate ? Carbon::parse($documentDate)->startOfMonth() : $arrived;
        if (in_array($receipt->source, ['upload', 'inbox'], true) && $date->lt($arrived)) {
            $date = $arrived;
        }

        return ['target_year' => (int) $date->year, 'target_month' => (int) $date->month];
    }
}
