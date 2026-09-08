<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'ernte:invoices:generate-recurring';

    protected $description = 'Generate invoices from due recurring schedules (catch-up safe).';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $today = Carbon::today();
        $generated = 0;
        $schedules = 0;
        $skipped = 0;
        $retried = 0;

        Invoice::query()
            ->where('status', 'draft')
            ->whereNotNull('recurring_occurrence_on')
            ->whereHas('recurringInvoice', fn ($query) => $query->where('auto_send', true))
            ->whereHas('events', fn ($query) => $query->whereIn('kind', [
                'recurring_autosend_failed',
                'recurring_autosend_skipped',
            ]))
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice) use ($generator, &$retried, &$skipped) {
                if ($generator->retryAutoSend($invoice)) {
                    $retried++;
                } else {
                    $skipped++;
                }
            });

        RecurringInvoice::query()
            ->due($today)
            ->orderBy('id')
            ->get()
            ->each(function (RecurringInvoice $schedule) use ($generator, $today, &$generated, &$schedules, &$skipped) {
                $schedules++;
                $guard = 0;

                while (! $schedule->isPaused()
                    && Carbon::parse($schedule->next_run_on)->lte($today)
                    && $guard++ < 60) {
                    $runDate = Carbon::parse($schedule->next_run_on);
                    $invoice = $generator->generate($schedule, $runDate);
                    $generated++;

                    if ($schedule->auto_send && $invoice->status !== 'sent') {
                        $skipped++;
                    }

                    // generate() advanced next_run_on; reload for the next loop iteration.
                    $schedule->refresh();
                }
            });

        $this->info("Generated {$generated} invoice(s) across {$schedules} schedule(s); retried {$retried} delivery failure(s); {$skipped} auto-send(s) pending.");

        return self::SUCCESS;
    }
}
