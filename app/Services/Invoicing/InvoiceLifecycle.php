<?php

namespace App\Services\Invoicing;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceLifecycle
{
    public function __construct(private InvoicePdfRenderer $pdf) {}

    /**
     * draft -> sent: stamp issued/due dates, render + freeze the PDF, email it, write events.
     *
     * The status flips under a row lock, so a double submit (or the web and MCP
     * racing) cannot issue and email the same invoice twice. The mail goes out
     * only after that commit: a mail sent inside the transaction would already be
     * with the client if the commit then failed. If the mail fails, the invoice is
     * put back to draft and the exception rethrown, so it stays retryable.
     *
     * The pdf_generated and sent events are written in the same transaction as
     * the status flip, so no crash can leave a sent invoice without its `sent`
     * event; a failed mail removes them again together with the status flip.
     */
    public function issue(Invoice $invoice): void
    {
        $before = $invoice->getAttributes();

        try {
            [$recipients, $path, $eventIds] = $this->claimAndRender($invoice);
        } catch (\Throwable $e) {
            // Rolled back: don't leave the caller's instance looking sent.
            $invoice->setRawAttributes($before, true);

            throw $e;
        }

        try {
            $to = array_map(fn ($r) => new Address($r['email'], $r['name']), $recipients);
            Mail::to($to[0])->cc(array_slice($to, 1))->send(new InvoiceMail($invoice, $path));
        } catch (\Throwable $e) {
            $this->revertToDraft($invoice, $before['pdf_path'] ?? null, $eventIds, $e);

            throw $e;
        }
    }

    /**
     * Under a row lock: check the invoice is still a draft, mark it sent, render its
     * PDF and write the pdf_generated + sent events.
     *
     * @return array{0: array<int, array{name: ?string, email: string}>, 1: string, 2: array<int, int>} recipients, PDF path, event ids
     */
    private function claimAndRender(Invoice $invoice): array
    {
        return DB::transaction(function () use ($invoice) {
            $this->lock($invoice);
            $invoice->loadMissing('client');

            if ($invoice->status !== 'draft') {
                throw new \DomainException("Only a draft can be sent (status: {$invoice->status}).");
            }

            $recipients = $invoice->effectiveRecipients();
            if (empty($recipients)) {
                throw new \DomainException('Cannot send invoice because the client has no contacts.');
            }

            $invoice->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);

            // Rendering can fail (no IBAN, invalid QR bill, Chromium); doing it
            // before the commit means such a failure simply leaves the draft as it was.
            $path = $this->pdf->pdf($invoice);

            return [$recipients, $path, [
                $this->event($invoice, 'pdf_generated', ['path' => $path])->id,
                $this->event($invoice, 'sent', ['email_to' => array_column($recipients, 'email'), 'pdf_path' => $path])->id,
            ]];
        });
    }

    /** Undo the status flip of issue() after the mail could not be delivered. */
    private function revertToDraft(Invoice $invoice, ?string $previousPdfPath, array $eventIds, \Throwable $cause): void
    {
        try {
            DB::transaction(function () use ($invoice, $previousPdfPath, $eventIds) {
                InvoiceEvent::whereKey($eventIds)->delete();
                $invoice->update([
                    'status' => 'draft',
                    'issued_on' => null,
                    'due_on' => null,
                    'sent_at' => null,
                    'pdf_path' => $previousPdfPath,
                ]);
            });
        } catch (\Throwable $e) {
            // Marked sent without a mail having gone out — nothing else will flag this.
            Log::critical("Invoice {$invoice->number} is marked sent but its email failed, and it could not be reset to draft.", [
                'invoice_id' => $invoice->id,
                'mail_error' => $cause->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * draft -> sent without emailing — used when the invoice was sent to the client
     * by other means. Stamps the same dates as issue(); the PDF is rendered lazily on
     * download, so this needs neither a client email nor QR-bill setup.
     */
    public function markSent(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->lock($invoice);

            if ($invoice->status !== 'draft') {
                throw new \DomainException("Only a draft can be marked as sent (status: {$invoice->status}).");
            }

            $invoice->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $this->event($invoice, 'sent', ['manual' => true]);
        });
    }

    /**
     * sent -> paid. $paidOn is the bank's booking date when a statement import marks the
     * invoice; the manual button passes nothing and stamps the current time.
     */
    public function markPaid(Invoice $invoice, ?CarbonInterface $paidOn = null, ?array $payload = null): void
    {
        DB::transaction(function () use ($invoice, $paidOn, $payload) {
            $this->lock($invoice);

            if ($invoice->status !== 'sent') {
                throw new \DomainException("Only a sent invoice can be marked paid (status: {$invoice->status}).");
            }

            $invoice->update(['status' => 'paid', 'paid_at' => $paidOn ?? now()]);
            $this->event($invoice, 'paid', $payload);
        });
    }

    /** A bank entry was linked to an invoice that was already marked paid by hand. */
    public function recordBankPayment(Invoice $invoice, CarbonInterface $paidOn, array $payload, bool $correctDate): void
    {
        DB::transaction(function () use ($invoice, $paidOn, $payload, $correctDate) {
            $this->lock($invoice);

            if ($invoice->status !== 'paid') {
                throw new \DomainException("Only a paid invoice can have its payment linked (status: {$invoice->status}).");
            }

            if ($correctDate && ! $invoice->paid_at?->isSameDay($paidOn)) {
                $payload['previous_paid_at'] = $invoice->paid_at?->toIso8601String();
                $invoice->update(['paid_at' => $paidOn]);
            }
            $this->event($invoice, 'payment_matched', $payload);
        });
    }

    /** paid -> sent: undo of a payment that a statement import had recorded. */
    public function reopen(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->lock($invoice);

            if ($invoice->status !== 'paid') {
                throw new \DomainException("Only a paid invoice can be reopened (status: {$invoice->status}).");
            }

            $invoice->update(['status' => 'sent', 'paid_at' => null]);
            $this->event($invoice, 'reopened');
        });
    }

    /** draft|sent -> void; releases linked entries so they can be re-invoiced. */
    public function void(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->lock($invoice);

            if (in_array($invoice->status, ['paid', 'void'], true)) {
                throw new \DomainException("Cannot void a {$invoice->status} invoice.");
            }

            TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->update(['status' => 'void']);
            $this->event($invoice, 'voided');
        });
    }

    /**
     * Take a row lock and reload the caller's instance from it, so every status
     * check below sees the committed state rather than what the request loaded
     * earlier. Must be called inside a transaction.
     */
    private function lock(Invoice $invoice): void
    {
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        $invoice->setRawAttributes($locked->getAttributes(), true);
    }

    private function event(Invoice $invoice, string $kind, ?array $payload = null): InvoiceEvent
    {
        return InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
