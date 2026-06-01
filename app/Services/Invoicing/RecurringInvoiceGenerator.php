<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceGenerator
{
    public function __construct(
        private InvoiceBuilder $builder,
        private InvoiceLifecycle $lifecycle,
    ) {}

    /**
     * Generate one invoice for the occurrence on $runDate, advance the schedule,
     * and (if auto_send) attempt to issue + email it. A failed auto-send leaves
     * the invoice as a draft and logs a recurring_autosend_skipped event.
     */
    public function generate(RecurringInvoice $schedule, Carbon $runDate): Invoice
    {
        $schedule->loadMissing([
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'client',
            'project',
        ]);

        $period = BillingPeriod::for($schedule->cadence, $runDate, $schedule->anchor_day);

        $title = $schedule->title !== null
            ? str_replace('{period}', $period['label'], $schedule->title)
            : null;

        $lines = $schedule->lines->map(fn (RecurringInvoiceLine $l) => [
            'description' => $l->description,
            'hours' => (float) $l->hours,
            'rate_rappen' => (int) $l->rate_rappen,
        ])->all();

        $invoice = DB::transaction(function () use ($schedule, $period, $title, $lines, $runDate) {
            $invoice = $this->builder->createDraft(
                client: $schedule->client,
                project: $schedule->project,
                periodStart: $period['start']->toDateString(),
                periodEnd: $period['end']->toDateString(),
                lines: $lines,
                entryIds: [],
                title: $title,
                notes: $schedule->notes,
                taxDate: $period['end']->toDateString(),
            );

            $invoice->recurring_invoice_id = $schedule->id;
            $invoice->save();

            $schedule->last_generated_on = $runDate->toDateString();
            $schedule->next_run_on = BillingPeriod::advance(
                $schedule->cadence,
                Carbon::parse($schedule->next_run_on),
                $schedule->anchor_day,
            )->toDateString();
            $schedule->save();

            return $invoice;
        });

        if ($schedule->auto_send) {
            try {
                $this->lifecycle->issue($invoice->fresh('client'));
            } catch (\DomainException $e) {
                InvoiceEvent::create([
                    'invoice_id' => $invoice->id,
                    'kind' => 'recurring_autosend_skipped',
                    'occurred_at' => now(),
                    'payload' => ['reason' => $e->getMessage()],
                ]);
            }
        }

        return $invoice->fresh(['lines', 'events']);
    }
}
