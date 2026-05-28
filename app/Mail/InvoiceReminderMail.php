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
            ->subject("Zahlungserinnerung Rechnung {$this->invoice->number}")
            ->view('emails.invoices.reminder')
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
