<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

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

        $failed = 0;

        // One bad invoice or schedule must not stop the rest of the nightly run.
        $guarded = function (string $what, callable $work) use (&$failed) {
            try {
                $work();
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$what} failed: {$e->getMessage()}");
            }
        };

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
            ->each(fn (Invoice $invoice) => $guarded("Retrying invoice {$invoice->number}", function () use ($generator, $invoice, &$retried, &$skipped) {
                if ($generator->retryAutoSend($invoice)) {
                    $retried++;
                } else {
                    $skipped++;
                }
            }));

        RecurringInvoice::query()
            ->due($today)
            ->orderBy('id')
            ->get()
            ->each(fn (RecurringInvoice $schedule) => $guarded("Recurring schedule #{$schedule->id}", function () use ($generator, $schedule, $today, &$generated, &$schedules, &$skipped) {
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

                    if (! Carbon::parse($schedule->next_run_on)->gt($runDate)) {
                        throw new \RuntimeException("next_run_on did not advance past {$runDate->toDateString()}.");
                    }
                }
            }));

        $this->info("Generated {$generated} invoice(s) across {$schedules} schedule(s); retried {$retried} delivery failure(s); {$skipped} auto-send(s) pending.");

        if ($failed > 0) {
            $this->error("{$failed} schedule(s) or retries failed; see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
