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
        $estimate->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('estimates.pdf', [
            'estimate' => $estimate,
            'profile' => BusinessProfile::current(),
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
            ->margins(12, 12, 12, 12)
            // The DDEV/container Chromium has no usable sandbox; this is required to launch it.
            ->noSandbox();

        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        return $shot;
    }
}
