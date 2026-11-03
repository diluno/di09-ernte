<?php

namespace App\Mail;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public BusinessProfile $profile;

    public function __construct(
        public Invoice $invoice,
        public string $pdfPath,
    ) {
        $this->invoice->loadMissing(['client', 'project', 'lines']);
        $this->profile = BusinessProfile::current();
        // Subject, body and attachment name follow the document's language.
        $this->locale($this->invoice->locale ?? \App\Support\DocumentLocale::DEFAULT);
    }

    public function build(): self
    {
        $from = $this->profile->email ?: config('mail.from.address');
        $name = $this->profile->name ?: config('mail.from.name');

        return $this
            ->from($from, $name)
            ->replyTo($from, $name)
            // Copy every outgoing mail to the operator so the sent mail is on file.
            ->bcc($from, $name)
            ->subject(implode(' - ', array_filter([__('mail.invoice_subject', ['number' => $this->invoice->number], $this->locale), $this->invoice->title, $name])))
            ->view('emails.invoices.sent')
            ->text('emails.invoices.sent-text')
            ->with([
                'invoice' => $this->invoice,
                'profile' => $this->profile,
            ])
            ->attach(Storage::disk('local')->path($this->pdfPath), [
                'as' => $this->invoice->pdfFilename(),
                'mime' => 'application/pdf',
            ]);
    }
}
