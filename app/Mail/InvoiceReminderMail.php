<?php

namespace App\Mail;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public BusinessProfile $profile;

    public function __construct(public Invoice $invoice)
    {
        $this->invoice->loadMissing(['client', 'project', 'lines']);
        $this->profile = BusinessProfile::current();
    }

    public function build(): self
    {
        $from = $this->profile->email ?: config('mail.from.address');
        $name = $this->profile->name ?: config('mail.from.name');

        $mail = $this
            ->from($from, $name)
            ->replyTo($from, $name)
            // Copy every outgoing mail to the operator so the sent mail is on file.
            ->bcc($from, $name)
            ->subject(implode(' - ', array_filter(["Zahlungserinnerung Rechnung {$this->invoice->number}", $this->invoice->title])))
            ->view('emails.invoices.reminder')
            ->text('emails.invoices.reminder-text')
            ->with([
                'invoice' => $this->invoice,
                'profile' => $this->profile,
            ]);

        if ($this->invoice->pdf_path && Storage::disk('local')->exists($this->invoice->pdf_path)) {
            $mail->attach(Storage::disk('local')->path($this->invoice->pdf_path), [
                'as' => "Rechnung-{$this->invoice->number}.pdf",
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
