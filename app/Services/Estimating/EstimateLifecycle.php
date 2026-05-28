<?php

namespace App\Services\Estimating;

use App\Mail\EstimateMail;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EstimateLifecycle
{
    public function __construct(
        private EstimatePdfRenderer $pdf,
        private InvoiceBuilder $invoiceBuilder,
    ) {}

    /** draft -> sent: stamp issued/valid dates, render + cache the PDF, email the client, write events. */
    public function send(Estimate $estimate): void
    {
        $estimate->loadMissing('client');

        if ($estimate->status !== 'draft') {
            throw new \DomainException("Only a draft can be sent (status: {$estimate->status}).");
        }

        if (! $estimate->client?->email) {
            throw new \DomainException('Cannot send estimate because the client has no email address.');
        }

        DB::transaction(function () use ($estimate) {
            $estimate->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $estimate->refresh();

            $path = $this->pdf->pdf($estimate);

            Mail::to($estimate->client->email)->send(new EstimateMail($estimate, $path));

            $this->event($estimate, 'pdf_generated', ['path' => $path]);
            $this->event($estimate, 'sent', ['email_to' => $estimate->client->email, 'pdf_path' => $path]);
        });
    }

    /**
     * draft -> sent without emailing — used when the estimate was sent to the client
     * by other means. Stamps the same dates as send(); the PDF is rendered lazily on
     * download, so this needs neither a client email nor a PDF render here.
     */
    public function markSent(Estimate $estimate): void
    {
        if ($estimate->status !== 'draft') {
            throw new \DomainException("Only a draft can be marked as sent (status: {$estimate->status}).");
        }

        DB::transaction(function () use ($estimate) {
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
        if ($estimate->status !== 'sent') {
            throw new \DomainException("Only a sent estimate can be accepted (status: {$estimate->status}).");
        }

        DB::transaction(function () use ($estimate) {
            $estimate->update(['status' => 'accepted', 'decided_at' => now()]);
            $this->event($estimate, 'accepted');
        });
    }

    /** sent -> declined. */
    public function decline(Estimate $estimate): void
    {
        if ($estimate->status !== 'sent') {
            throw new \DomainException("Only a sent estimate can be declined (status: {$estimate->status}).");
        }

        DB::transaction(function () use ($estimate) {
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
        if ($estimate->status !== 'accepted') {
            throw new \DomainException("Only an accepted estimate can be converted (status: {$estimate->status}).");
        }
        if ($estimate->converted_invoice_id !== null) {
            throw new \DomainException("Estimate {$estimate->number} has already been converted.");
        }

        $estimate->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return DB::transaction(function () use ($estimate) {
            $lines = $estimate->lines->map(fn ($l) => [
                'description' => $l->description,
                'hours' => (float) $l->hours,
                'rate_rappen' => (int) $l->rate_rappen,
                'vat_exempt' => (bool) $l->vat_exempt,
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
            );

            $estimate->update(['converted_invoice_id' => $invoice->id]);
            $this->event($estimate, 'converted', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number]);

            return $invoice;
        });
    }

    private function event(Estimate $estimate, string $kind, ?array $payload = null): void
    {
        EstimateEvent::create([
            'estimate_id' => $estimate->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
