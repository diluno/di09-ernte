<?php

namespace App\Jobs;

use App\Mail\InvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendInvoiceReminderMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $invoice = Invoice::query()->with('client')->find($this->invoiceId);

        if (! $invoice || ! $this->shouldRemind($invoice)) {
            return;
        }

        $recipients = $invoice->client?->defaultRecipients() ?? [];

        $to = array_map(fn ($r) => new Address($r['email'], $r['name']), $recipients);
        Mail::to($to[0])->cc(array_slice($to, 1))->send(new InvoiceReminderMail($invoice));

        InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'kind' => 'reminded',
            'occurred_at' => now(),
            'payload' => [
                'email_to' => array_column($recipients, 'email'),
                'days_overdue' => $invoice->due_on?->diffInDays(Carbon::today()) ?? 0,
            ],
        ]);
    }

    private function shouldRemind(Invoice $invoice): bool
    {
        if ($invoice->status !== 'sent' || ! $invoice->due_on || ! $invoice->due_on->lt(Carbon::today())) {
            return false;
        }

        if (empty($invoice->client?->defaultRecipients())) {
            return false;
        }

        $days = max(1, BusinessProfile::current()->reminder_days_after_due ?? 7);
        $threshold = now()->subDays($days);

        return ! $invoice->events()
            ->where('kind', 'reminded')
            ->where('occurred_at', '>=', $threshold)
            ->exists();
    }
}
