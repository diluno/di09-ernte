<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class StampOverdueInvoicesCommand extends Command
{
    protected $signature = 'ernte:invoices:stamp-overdue';

    protected $description = 'Write one overdue-stamped event for sent invoices that became overdue.';

    public function handle(): int
    {
        $count = 0;

        Invoice::query()
            ->where('status', 'sent')
            ->whereDate('due_on', '<', Carbon::today()->toDateString())
            ->whereDoesntHave('events', fn ($q) => $q->where('kind', 'overdue_stamped'))
            ->each(function (Invoice $invoice) use (&$count) {
                InvoiceEvent::create([
                    'invoice_id' => $invoice->id,
                    'kind' => 'overdue_stamped',
                    'occurred_at' => now(),
                    'payload' => [
                        'due_on' => $invoice->due_on?->toDateString(),
                        'days_overdue' => $invoice->due_on?->diffInDays(Carbon::today()) ?? 0,
                    ],
                ]);
                $count++;
            });

        $this->info("Stamped {$count} overdue invoice(s).");

        return self::SUCCESS;
    }
}
