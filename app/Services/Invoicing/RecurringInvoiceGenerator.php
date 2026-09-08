<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecurringInvoiceGenerator
{
    public function __construct(
        private InvoiceBuilder $builder,
        private InvoiceLifecycle $lifecycle,
    ) {}

    /**
     * Generate one invoice for the occurrence on $runDate, advance the schedule,
     * and (if auto_send) attempt to issue + email it. Generation is idempotent
     * per schedule/run date, and failed delivery leaves the invoice retryable.
     */
    public function generate(RecurringInvoice $schedule, Carbon $runDate): Invoice
    {
        $runDate = $runDate->copy()->startOfDay();

        $invoice = DB::transaction(function () use ($schedule, $runDate) {
            $lockedSchedule = RecurringInvoice::query()
                ->lockForUpdate()
                ->findOrFail($schedule->id);

            $existing = Invoice::query()
                ->where('recurring_invoice_id', $lockedSchedule->id)
                ->whereDate('recurring_occurrence_on', $runDate)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($lockedSchedule->isPaused()) {
                throw new \DomainException('Cannot generate from a paused recurring schedule.');
            }

            if (! $lockedSchedule->next_run_on->isSameDay($runDate)) {
                throw new \DomainException('This recurring occurrence has already advanced or is no longer current.');
            }

            $lockedSchedule->load([
                'lines' => fn ($query) => $query->orderBy('sort_order'),
                'client',
                'project',
            ]);

            $period = BillingPeriod::for($lockedSchedule->cadence, $runDate, $lockedSchedule->anchor_day);
            $title = $lockedSchedule->title !== null
                ? str_replace('{period}', $period['label'], $lockedSchedule->title)
                : null;
            $lines = $lockedSchedule->lines->map(fn (RecurringInvoiceLine $line) => [
                'description' => $line->description,
                'hours' => (float) $line->hours,
                'rate_rappen' => (int) $line->rate_rappen,
            ])->all();

            $invoice = $this->builder->createDraft(
                client: $lockedSchedule->client,
                project: $lockedSchedule->project,
                periodStart: $period['start']->toDateString(),
                periodEnd: $period['end']->toDateString(),
                lines: $lines,
                entryIds: [],
                title: $title,
                notes: $lockedSchedule->notes,
                taxDate: $period['end']->toDateString(),
            );

            $invoice->recurring_invoice_id = $lockedSchedule->id;
            $invoice->recurring_occurrence_on = $runDate->toDateString();
            $invoice->recipients = $lockedSchedule->recipients;
            $invoice->save();

            $lockedSchedule->last_generated_on = $runDate->toDateString();
            $lockedSchedule->next_run_on = BillingPeriod::advance(
                $lockedSchedule->cadence,
                $runDate,
                $lockedSchedule->anchor_day,
            )->toDateString();
            $lockedSchedule->save();

            return $invoice;
        });

        $schedule->refresh();
        if ($schedule->auto_send && $invoice->status === 'draft') {
            $this->retryAutoSend($invoice);
        }

        return $invoice->fresh(['lines', 'events']);
    }

    /** Retry delivery for an existing draft created by an auto-send schedule. */
    public function retryAutoSend(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::query()
                ->with(['client', 'recurringInvoice'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($lockedInvoice->status !== 'draft' || ! $lockedInvoice->recurringInvoice?->auto_send) {
                return $lockedInvoice->status === 'sent';
            }

            try {
                $this->lifecycle->issue($lockedInvoice);

                return true;
            } catch (Throwable $exception) {
                InvoiceEvent::create([
                    'invoice_id' => $lockedInvoice->id,
                    'kind' => 'recurring_autosend_failed',
                    'occurred_at' => now(),
                    'payload' => [
                        'reason' => $exception->getMessage(),
                        'exception' => $exception::class,
                        'retryable' => true,
                    ],
                ]);

                Log::error('Recurring invoice auto-send failed.', [
                    'invoice_id' => $lockedInvoice->id,
                    'recurring_invoice_id' => $lockedInvoice->recurring_invoice_id,
                    'exception' => $exception,
                ]);

                return false;
            }
        });
    }
}
