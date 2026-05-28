<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;

class InvoiceLifecycle
{
    public function __construct(private InvoicePdfRenderer $pdf) {}

    /**
     * draft -> sent: stamp issued/due dates, render + cache the PDF, write events.
     * NOTE: email dispatch is added in Phase 2b-ii — this method intentionally does not mail.
     */
    public function issue(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new \DomainException("Only a draft can be sent (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $invoice->refresh();

            $path = $this->pdf->pdf($invoice);

            $this->event($invoice, 'pdf_generated', ['path' => $path]);
            $this->event($invoice, 'sent');
        });
    }

    /** sent -> paid. */
    public function markPaid(Invoice $invoice): void
    {
        if ($invoice->status !== 'sent') {
            throw new \DomainException("Only a sent invoice can be marked paid (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $this->event($invoice, 'paid');
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
