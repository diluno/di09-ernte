<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Support\BillingPeriod;
use App\Support\DocumentLocale;
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
                // The occurrence exists but the schedule still points at it (e.g. the
                // advance was lost, or the date was moved back onto it). Step past it,
                // or every run would find this invoice again and never move on.
                if ($lockedSchedule->next_run_on->lte($runDate)) {
                    $this->advance($lockedSchedule, $runDate);
                }

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

            $period = BillingPeriod::for(
                $lockedSchedule->cadence,
                $runDate,
                $lockedSchedule->anchor_day,
                $lockedSchedule->client->locale ?? DocumentLocale::DEFAULT,
            );
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

            $this->advance($lockedSchedule, $runDate);

            return $invoice;
        });

        $schedule->refresh();
        if ($schedule->auto_send && $invoice->status === 'draft') {
            $this->retryAutoSend($invoice);
        }

        return $invoice->fresh(['lines', 'events']);
    }

    private function advance(RecurringInvoice $schedule, Carbon $runDate): void
    {
        $schedule->last_generated_on = $runDate->toDateString();
        $schedule->next_run_on = BillingPeriod::advance(
            $schedule->cadence,
            $runDate,
            $schedule->anchor_day,
        )->toDateString();
        $schedule->save();
    }

    /**
     * Retry delivery for an existing draft created by an auto-send schedule.
     * Not wrapped in a transaction: issue() takes its own row lock and must
     * commit before it mails.
     */
    public function retryAutoSend(Invoice $invoice): bool
    {
        $invoice = Invoice::query()->with(['client', 'recurringInvoice'])->findOrFail($invoice->id);

        if ($invoice->status !== 'draft' || ! $invoice->recurringInvoice?->auto_send) {
            return $invoice->status === 'sent';
        }

        try {
            $this->lifecycle->issue($invoice);

            return true;
        } catch (Throwable $exception) {
            // Lost a race against another sender: the invoice went out, nothing failed.
            if ($invoice->fresh()?->status === 'sent') {
                return true;
            }

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'recurring_autosend_failed',
                'occurred_at' => now(),
                'payload' => [
                    'reason' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'retryable' => true,
                ],
            ]);

            Log::error('Recurring invoice auto-send failed.', [
                'invoice_id' => $invoice->id,
                'recurring_invoice_id' => $invoice->recurring_invoice_id,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
