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

    /**
     * $force skips the pause flag and the cadence check — used by the explicit
     * "Send reminder now" action, where the click itself is the intent.
     */
    public function __construct(public int $invoiceId, public bool $force = false)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $invoice = Invoice::query()->with('client')->find($this->invoiceId);

        if (! $invoice || ! $this->shouldRemind($invoice)) {
            return;
        }

        $recipients = $invoice->effectiveRecipients();

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
        if ($invoice->status !== 'sent') {
            return false;
        }

        if (empty($invoice->effectiveRecipients())) {
            return false;
        }

        // A double submit (or a retried job) must not mail the client twice.
        if (self::remindedJustNow($invoice)) {
            return false;
        }

        if ($this->force) {
            return true;
        }

        if ($invoice->reminders_paused_at || ! $invoice->due_on || ! $invoice->due_on->lt(Carbon::today())) {
            return false;
        }

        $days = max(1, BusinessProfile::current()->reminder_days_after_due ?? 7);

        return ! self::remindedWithinDays($invoice, $days);
    }

    /**
     * Whether a reminder went out fewer than $days calendar days ago. Compared by
     * date, not by timestamp: the nightly run exactly $days days later starts a
     * few seconds before or after the previous one, and must still count as due.
     */
    public static function remindedWithinDays(Invoice $invoice, int $days): bool
    {
        return $invoice->events()
            ->where('kind', 'reminded')
            ->whereDate('occurred_at', '>', Carbon::today()->subDays($days))
            ->exists();
    }

    public static function remindedJustNow(Invoice $invoice): bool
    {
        return $invoice->events()
            ->where('kind', 'reminded')
            ->where('occurred_at', '>=', now()->subMinute())
            ->exists();
    }
}
