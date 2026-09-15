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
        $invoice->loadMissing(['client.contacts', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('documents.pdf', [
            'doc' => $invoice,
            'kind' => 'invoice',
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

        $this->browsershot($invoice)->save($absolute);

        $invoice->update(['pdf_path' => $relative]);

        return $relative;
    }

    /** Render a PDF without caching it on the invoice. */
    public function pdfBytes(Invoice $invoice): string
    {
        return $this->browsershot($invoice)->pdf();
    }

    private function browsershot(Invoice $invoice): Browsershot
    {
        $shot = Browsershot::html($this->html($invoice))
            ->format('A4')
            ->showBackground()
            // Side margins live in the sheet CSS (the payment part spans the full
            // 210mm); the 10mm bottom margin hosts Chrome's running footer.
            ->margins(0, 0, 10, 0)
            ->showBrowserHeaderAndFooter()
            ->headerHtml('<span></span>')
            ->footerHtml($this->footer('Rechnung', $invoice->number))
            // The DDEV/container Chromium has no usable sandbox; this is required to launch it.
            ->noSandbox();

        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        return $shot;
    }

    /** Chrome footer template: document number + page counter in the bottom margin. */
    private function footer(string $label, string $number): string
    {
        return '<div style="width:100%;padding:0 20mm;font-family:DejaVu Sans Mono,Menlo,monospace;font-size:7pt;color:#7a7367;display:flex;justify-content:space-between">'
            .'<span>'.e($label).' '.e($number).'</span>'
            .'<span>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';
    }
}
