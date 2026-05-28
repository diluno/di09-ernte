<?php

namespace App\Mail;

use App\Models\BusinessProfile;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EstimateMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public BusinessProfile $profile;

    public function __construct(
        public Estimate $estimate,
        public string $pdfPath,
    ) {
        $this->estimate->loadMissing(['client', 'project', 'lines']);
        $this->profile = BusinessProfile::current();
    }

    public function build(): self
    {
        $from = $this->profile->email ?: config('mail.from.address');
        $name = $this->profile->name ?: config('mail.from.name');

        return $this
            ->from($from, $name)
            ->replyTo($from, $name)
            ->subject("Offerte {$this->estimate->number} - {$name}")
            ->view('emails.estimates.sent')
            ->with([
                'estimate' => $this->estimate,
                'profile' => $this->profile,
            ])
            ->attach(Storage::disk('local')->path($this->pdfPath), [
                'as' => "Offerte-{$this->estimate->number}.pdf",
                'mime' => 'application/pdf',
            ]);
    }
}
