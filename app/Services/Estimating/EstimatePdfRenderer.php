<?php

namespace App\Services\Estimating;

use App\Models\BusinessProfile;
use App\Models\Estimate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class EstimatePdfRenderer
{
    /** Render the estimate document to an HTML string (used by /preview and the PDF). */
    public function html(Estimate $estimate): string
    {
        $estimate->loadMissing(['client.contacts', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('documents.pdf', [
            'doc' => $estimate,
            'kind' => 'estimate',
            'profile' => BusinessProfile::current(),
            'qrBillHtml' => null,
        ])->render();
    }

    /** Render to a cached PDF on the local disk; returns the storage-relative path. */
    public function pdf(Estimate $estimate): string
    {
        $relative = "estimates/{$estimate->number}.pdf";
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir($dir = dirname($absolute))) {
            mkdir($dir, 0775, true);
        }

        $this->browsershot($estimate)->save($absolute);

        $estimate->update(['pdf_path' => $relative]);

        return $relative;
    }

    /** Render a PDF without caching it on the estimate. */
    public function pdfBytes(Estimate $estimate): string
    {
        return $this->browsershot($estimate)->pdf();
    }

    private function browsershot(Estimate $estimate): Browsershot
    {
        $shot = Browsershot::html($this->html($estimate))
            ->format('A4')
            ->showBackground()
            // Side margins live in the sheet CSS (the payment part spans the full
            // 210mm); the 10mm bottom margin hosts Chrome's running footer.
            ->margins(0, 0, 10, 0)
            ->showBrowserHeaderAndFooter()
            ->headerHtml('<span></span>')
            ->footerHtml($this->footer('Offerte', $estimate->number))
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
