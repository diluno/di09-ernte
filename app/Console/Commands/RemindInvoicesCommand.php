<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RemindInvoicesCommand extends Command
{
    protected $signature = 'ernte:invoices:remind';

    protected $description = 'Queue reminder emails for overdue sent invoices.';

    public function handle(): int
    {
        $days = max(1, BusinessProfile::current()->reminder_days_after_due ?? 7);
        $dueThreshold = Carbon::today()->subDays($days)->toDateString();
        $recentThreshold = now()->subDays($days);

        $candidates = Invoice::query()
            ->with('client')
            ->where('status', 'sent')
            ->whereDate('due_on', '<=', $dueThreshold)
            ->get();

        $queued = 0;
        $missingEmail = 0;
        $recent = 0;

        foreach ($candidates as $invoice) {
            if (! $invoice->client?->email) {
                $missingEmail++;
                continue;
            }

            $recentlyReminded = $invoice->events()
                ->where('kind', 'reminded')
                ->where('occurred_at', '>=', $recentThreshold)
                ->exists();

            if ($recentlyReminded) {
                $recent++;
                continue;
            }

            SendInvoiceReminderMail::dispatch($invoice->id);
            $queued++;
        }

        $this->info("Queued {$queued} reminder(s); skipped {$missingEmail} missing email; skipped {$recent} recently reminded.");

        return self::SUCCESS;
    }
}
