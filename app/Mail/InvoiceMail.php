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
    }

    public function build(): self
    {
        $from = $this->profile->email ?: config('mail.from.address');
        $name = $this->profile->name ?: config('mail.from.name');

        return $this
            ->from($from, $name)
            ->replyTo($from, $name)
            ->subject("Rechnung {$this->invoice->number} - {$name}")
            ->text('emails.invoices.sent')
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
