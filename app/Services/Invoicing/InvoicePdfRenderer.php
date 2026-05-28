<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class InvoicePdfRenderer
{
    public function __construct(private QrBillRenderer $qr) {}

    /** Render the invoice document to an HTML string (used by /preview and the PDF). */
    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('invoices.pdf', [
            'invoice' => $invoice,
            'profile' => BusinessProfile::current(),
            'qrBillHtml' => $this->qr->html($invoice),
        ])->render();
    }

    /** Render to a cached PDF on the local disk; returns the storage-relative path. */
    public function pdf(Invoice $invoice): string
    {
        $relative = "invoices/{$invoice->number}.pdf";
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir($dir = dirname($absolute))) {
            mkdir($dir, 0775, true);
        }

        $shot = Browsershot::html($this->html($invoice))
            ->format('A4')
            ->showBackground()
            ->margins(12, 12, 12, 12)
            // The DDEV/container Chromium has no usable sandbox; this is required to launch it.
            ->noSandbox();

        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        $shot->save($absolute);

        $invoice->update(['pdf_path' => $relative]);

        return $relative;
    }
}
