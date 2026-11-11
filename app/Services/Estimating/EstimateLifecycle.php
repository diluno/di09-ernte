<?php

namespace App\Services\Estimating;

use App\Mail\EstimateMail;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceBuilder;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EstimateLifecycle
{
    public function __construct(
        private EstimatePdfRenderer $pdf,
        private InvoiceBuilder $invoiceBuilder,
    ) {}

    /**
     * draft -> sent: stamp issued/valid dates, render + freeze the PDF, email the client, write events.
     *
     * Same shape as InvoiceLifecycle::issue(): the status flips under a row lock
     * (no double send), the mail goes out after the commit, and a failed mail puts
     * the estimate back to draft so it can be retried. The pdf_generated and sent
     * events are written in that same transaction (and removed again with a failed
     * mail), so no crash can leave a sent estimate without its `sent` event.
     */
    public function send(Estimate $estimate): void
    {
        $before = $estimate->getAttributes();

        try {
            [$recipients, $path, $eventIds] = DB::transaction(function () use ($estimate) {
                $this->lock($estimate);
                $estimate->loadMissing('client');

                if ($estimate->status !== 'draft') {
                    throw new \DomainException("Only a draft can be sent (status: {$estimate->status}).");
                }

                $recipients = $estimate->effectiveRecipients();
                if (empty($recipients)) {
                    throw new \DomainException('Cannot send estimate because the client has no contacts.');
                }

                $estimate->update([
                    'status' => 'sent',
                    'issued_on' => now()->toDateString(),
                    'valid_until' => now()->addDays(30)->toDateString(),
                    'sent_at' => now(),
                ]);

                $path = $this->pdf->pdf($estimate);

                return [$recipients, $path, [
                    $this->event($estimate, 'pdf_generated', ['path' => $path])->id,
                    $this->event($estimate, 'sent', ['email_to' => array_column($recipients, 'email'), 'pdf_path' => $path])->id,
                ]];
            });
        } catch (\Throwable $e) {
            // Rolled back: don't leave the caller's instance looking sent.
            $estimate->setRawAttributes($before, true);

            throw $e;
        }

        try {
            $to = array_map(fn ($r) => new Address($r['email'], $r['name']), $recipients);
            Mail::to($to[0])->cc(array_slice($to, 1))->send(new EstimateMail($estimate, $path));
        } catch (\Throwable $e) {
            $this->revertToDraft($estimate, $before['pdf_path'] ?? null, $eventIds, $e);

            throw $e;
        }
    }

    /** Undo the status flip of send() after the mail could not be delivered. */
    private function revertToDraft(Estimate $estimate, ?string $previousPdfPath, array $eventIds, \Throwable $cause): void
    {
        try {
            DB::transaction(function () use ($estimate, $previousPdfPath, $eventIds) {
                EstimateEvent::whereKey($eventIds)->delete();
                $estimate->update([
                    'status' => 'draft',
                    'issued_on' => null,
                    'valid_until' => null,
                    'sent_at' => null,
                    'pdf_path' => $previousPdfPath,
                ]);
            });
        } catch (\Throwable $e) {
            // Marked sent without a mail having gone out — nothing else will flag this.
            Log::critical("Estimate {$estimate->number} is marked sent but its email failed, and it could not be reset to draft.", [
                'estimate_id' => $estimate->id,
                'mail_error' => $cause->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * draft -> sent without emailing — used when the estimate was sent to the client
     * by other means. Stamps the same dates as send(); the PDF is rendered lazily on
     * download, so this needs neither a client email nor a PDF render here.
     */
    public function markSent(Estimate $estimate): void
    {
        DB::transaction(function () use ($estimate) {
            $this->lock($estimate);

            if ($estimate->status !== 'draft') {
                throw new \DomainException("Only a draft can be marked as sent (status: {$estimate->status}).");
            }

            $estimate->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $this->event($estimate, 'sent', ['manual' => true]);
        });
    }

    /** sent -> accepted. */
    public function accept(Estimate $estimate): void
    {
        DB::transaction(function () use ($estimate) {
            $this->lock($estimate);

            if ($estimate->status !== 'sent') {
                throw new \DomainException("Only a sent estimate can be accepted (status: {$estimate->status}).");
            }

            $estimate->update(['status' => 'accepted', 'decided_at' => now()]);
            $this->event($estimate, 'accepted');
        });
    }

    /** sent -> declined. */
    public function decline(Estimate $estimate): void
    {
        DB::transaction(function () use ($estimate) {
            $this->lock($estimate);

            if ($estimate->status !== 'sent') {
                throw new \DomainException("Only a sent estimate can be declined (status: {$estimate->status}).");
            }

            $estimate->update(['status' => 'declined', 'decided_at' => now()]);
            $this->event($estimate, 'declined');
        });
    }

    /**
     * Build a draft invoice from an accepted estimate's lines and link the two.
     * The resulting invoice is a normal draft — the user sends it through the
     * invoice flow (which adds the QR-bill).
     */
    public function convertToInvoice(Estimate $estimate): Invoice
    {
        return DB::transaction(function () use ($estimate) {
            // Checked under the lock: a double submit must not create two invoices.
            $this->lock($estimate);

            if ($estimate->status !== 'accepted') {
                throw new \DomainException("Only an accepted estimate can be converted (status: {$estimate->status}).");
            }
            if ($estimate->converted_invoice_id !== null) {
                throw new \DomainException("Estimate {$estimate->number} has already been converted.");
            }

            $estimate->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

            // Invoice lines have a single text field; titled lines fold their
            // title in as a bold first line.
            $lines = $estimate->lines->map(fn ($l) => [
                'description' => $l->combinedDescription(),
                'hours' => (float) $l->hours,
                'rate_rappen' => (int) $l->rate_rappen,
            ])->all();

            $today = now()->toDateString();

            $invoice = $this->invoiceBuilder->createDraft(
                client: $estimate->client,
                project: $estimate->project,
                periodStart: $today,
                periodEnd: $today,
                lines: $lines,
                entryIds: [],
                title: $estimate->title,
                notes: $estimate->notes,
                taxDate: $today,
            );

            // The invoice continues in the language the client saw the estimate in.
            $invoice->update(['locale' => $estimate->locale]);
            $estimate->update(['converted_invoice_id' => $invoice->id]);
            $this->event($estimate, 'converted', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number]);

            return $invoice;
        });
    }

    /**
     * Take a row lock and reload the caller's instance from it, so every status
     * check sees the committed state rather than what the request loaded earlier.
     * Must be called inside a transaction.
     */
    private function lock(Estimate $estimate): void
    {
        $locked = Estimate::query()->whereKey($estimate->getKey())->lockForUpdate()->firstOrFail();

        $estimate->setRawAttributes($locked->getAttributes(), true);
    }

    private function event(Estimate $estimate, string $kind, ?array $payload = null): EstimateEvent
    {
        return EstimateEvent::create([
            'estimate_id' => $estimate->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
