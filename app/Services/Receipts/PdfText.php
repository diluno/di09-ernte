<?php

namespace App\Services\Receipts;

use Illuminate\Support\Facades\Process;

/** The PDF's text layer via poppler's pdftotext, as sorter.py and ccmatch.py read it. */
class PdfText
{
    /** Null for scans and photos, and when pdftotext is unavailable — never an error. */
    public function extract(string $absolutePath): ?string
    {
        try {
            $result = Process::timeout(30)->run([
                config('services.receipts.pdftotext_path', 'pdftotext'), '-layout', $absolutePath, '-',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }
        $text = trim(str_replace("\f", "\n", $result->output()));

        return $text !== '' ? $text : null;
    }
}
