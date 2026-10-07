<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use Illuminate\Support\Str;

/** The accountant's folder and naming convention, in one place. */
class ReceiptPaths
{
    /** "<root>/2026_Q3" */
    public static function quarterFolder(string $root, int $year, int $month): string
    {
        return sprintf('%s/%d_Q%d', rtrim($root, '/'), $year, intdiv($month - 1, 3) + 1);
    }

    /** "<root>/2026_Q3/09" */
    public static function monthFolder(string $root, int $year, int $month): string
    {
        return sprintf('%s/%02d', self::quarterFolder($root, $year, $month), $month);
    }

    /** "2026_Q3/09" for display. */
    public static function label(?int $year, ?int $month): ?string
    {
        return $year && $month ? ltrim(self::monthFolder('', $year, $month), '/') : null;
    }

    /**
     * A PDF filename Dropbox accepts: no path separators or reserved characters, no
     * leading/trailing dots or spaces, always ending in ".pdf".
     */
    public static function sanitise(string $name): string
    {
        $base = preg_replace('/\.(pdf|jpe?g|png|hei[cf])$/i', '', trim($name));
        $base = preg_replace('/[\/\\\\<>:"|?*\x00-\x1F\x7F]+/u', '-', $base);
        $base = trim(preg_replace('/\s+/u', ' ', $base), " .-\t");
        $base = mb_substr($base, 0, 180);

        return ($base !== '' ? $base : 'Beleg').'.pdf';
    }

    /** A name a camera or scanner made up ("Scan 7 Oct 2026.pdf", "IMG_4821.pdf") says nothing. */
    public static function isScanName(string $name): bool
    {
        $name = trim($name);

        // The Dropbox app's scanner names a scan after the moment it was taken: "2026-10-07 15.24.09.pdf".
        return preg_match('/^\d{4}-\d{2}-\d{2}[ _T]\d{2}[.:\-]\d{2}([.:\-]\d{2})?(\s*\(\d+\))?\.[a-z]+$/i', $name) === 1
            || preg_match('/^(scan|scans|scanned|scannen|gescannt|img|image|photo|foto|bild|dokument|document|doc|untitled|unbenannt)(?![a-z])/i', $name) === 1;
    }

    /** Whether ernte makes up the filename (and so may vary it on a clash) rather than keeping one. */
    public static function isGenerated(Receipt $receipt): bool
    {
        return ! $receipt->filename && ($receipt->isPhoto() || self::isScanName($receipt->original_name));
    }

    /** The name a receipt is first filed under. */
    public static function initialName(Receipt $receipt): string
    {
        if ($receipt->filename) {
            return self::sanitise($receipt->filename);
        }
        if (! self::isGenerated($receipt)) {
            return self::sanitise($receipt->original_name);
        }

        $vendor = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii((string) $receipt->vendor)), '-');
        if ($receipt->document_date && $vendor !== '') {
            return $receipt->document_date->format('Y-m-d').'_'.mb_substr($vendor, 0, 60).'.pdf';
        }

        return sprintf('Beleg_%s_%s.pdf', $receipt->created_at->timezone('Europe/Zurich')->format('Y-m-d'), substr($receipt->content_hash, 0, 6));
    }

    /** "a.pdf" + 2 -> "a_2.pdf" */
    public static function withSuffix(string $name, int $n): string
    {
        return preg_replace('/\.pdf$/i', "_{$n}.pdf", $name);
    }
}
