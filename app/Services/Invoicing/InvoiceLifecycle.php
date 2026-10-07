<?php

namespace App\Services\Invoicing;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InvoiceLifecycle
{
    public function __construct(private InvoicePdfRenderer $pdf) {}

    /**
     * draft -> sent: stamp issued/due dates, render + cache the PDF, write events.
     * NOTE: email dispatch is added in Phase 2b-ii — this method intentionally does not mail.
     */
    public function issue(Invoice $invoice): void
    {
        $invoice->loadMissing('client');

        if ($invoice->status !== 'draft') {
            throw new \DomainException("Only a draft can be sent (status: {$invoice->status}).");
        }

        $recipients = $invoice->recipients ?: ($invoice->client?->defaultRecipients() ?? []);
        if (empty($recipients)) {
            throw new \DomainException('Cannot send invoice because the client has no contacts.');
        }

        DB::transaction(function () use ($invoice, $recipients) {
            $invoice->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $invoice->refresh();

            $path = $this->pdf->pdf($invoice);

            $to = array_map(fn ($r) => new Address($r['email'], $r['name']), $recipients);
            Mail::to($to[0])->cc(array_slice($to, 1))->send(new InvoiceMail($invoice, $path));

            $this->event($invoice, 'pdf_generated', ['path' => $path]);
            $this->event($invoice, 'sent', ['email_to' => array_column($recipients, 'email'), 'pdf_path' => $path]);
        });
    }

    /**
     * draft -> sent without emailing — used when the invoice was sent to the client
     * by other means. Stamps the same dates as issue(); the PDF is rendered lazily on
     * download, so this needs neither a client email nor QR-bill setup.
     */
    public function markSent(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new \DomainException("Only a draft can be marked as sent (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice) {
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
        if ($invoice->status !== 'sent') {
            throw new \DomainException("Only a sent invoice can be marked paid (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice, $paidOn, $payload) {
            $invoice->update(['status' => 'paid', 'paid_at' => $paidOn ?? now()]);
            $this->event($invoice, 'paid', $payload);
        });
    }

    /** A bank entry was linked to an invoice that was already marked paid by hand. */
    public function recordBankPayment(Invoice $invoice, CarbonInterface $paidOn, array $payload, bool $correctDate): void
    {
        if ($invoice->status !== 'paid') {
            throw new \DomainException("Only a paid invoice can have its payment linked (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice, $paidOn, $payload, $correctDate) {
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
        if ($invoice->status !== 'paid') {
            throw new \DomainException("Only a paid invoice can be reopened (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'sent', 'paid_at' => null]);
            $this->event($invoice, 'reopened');
        });
    }

    /** draft|sent -> void; releases linked entries so they can be re-invoiced. */
    public function void(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            throw new \DomainException("Cannot void a {$invoice->status} invoice.");
        }

        DB::transaction(function () use ($invoice) {
            TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->update(['status' => 'void']);
            $this->event($invoice, 'voided');
        });
    }

    private function event(Invoice $invoice, string $kind, ?array $payload = null): void
    {
        InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
