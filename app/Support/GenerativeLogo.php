<?php

namespace App\Support;

/**
 * PHP port of the di03-logo generator: the six "diluno" glyphs are densely
 * overlapped (stroke only) to fill a randomly-distributed share of the width,
 * producing a unique-but-reproducible mark per document. The random
 * distribution is seeded so the same document always renders identically.
 */
class GenerativeLogo
{
    /** Glyph outline paths and their bounding-box widths, in viewBox units. */
    private const GLYPHS = [
        'd' => ['M36.15,65 L36.15,58.43 C33.45,63.02 28.5,65.99 21.93,65.99 C10.23,65.99 0.24,57.44 0.24,41.33 C0.24,25.85 10.59,17.3 22.02,17.3 C28.5,17.3 33.27,19.91 35.97,24.05 L35.97,0.2 L47.58,0.2 L47.58,65 L36.15,65 Z M24.18,56.45 C30.84,56.45 36.24,50.87 36.24,41.33 C36.24,32.15 31.11,26.84 24.18,26.84 C17.16,26.84 12.12,32.24 12.12,41.33 C12.12,50.87 17.16,56.45 24.18,56.45 Z', 47.34],
        'i' => ['M0,11 L0,0.2 L11.7,0.2 L11.7,11 L0,11 Z M0.09,65 L0.09,18.38 L11.61,18.38 L11.61,65 L0.09,65 Z', 11.7],
        'l' => ['M0 65 0 0.2 11.52 0.2 11.52 65z', 11.52],
        'u' => ['M30.51,65 L30.51,59.51 C27.54,63.83 22.77,65.99 16.92,65.99 C7.02,65.99 0,59.78 0,47.63 L0,18.38 L11.61,18.38 L11.61,45.74 C11.61,52.4 15.21,56.09 20.7,56.09 C25.65,56.09 30.24,52.31 30.24,44.12 L30.24,18.38 L41.76,18.38 L41.76,65 L30.51,65 Z', 41.76],
        'n' => ['M11.52,39.71 L11.52,65 L0,65 L0,18.38 L11.34,18.38 L11.34,23.96 C14.31,19.73 19.35,17.3 25.29,17.3 C35.55,17.3 42.21,23.69 42.21,35.93 L42.21,65 L30.69,65 L30.69,37.64 C30.69,31.25 27.27,27.2 21.42,27.2 C16.11,27.2 11.52,31.25 11.52,39.71 Z', 42.21],
        'o' => ['M24.57,65.99 C10.89,65.99 0,56.54 0,41.42 C0,26.75 11.07,17.3 24.57,17.3 C38.16,17.3 49.05,26.57 49.05,41.42 C49.05,56.36 38.07,65.99 24.57,65.99 Z M24.57,56.27 C32.04,56.27 37.17,50.33 37.17,41.42 C37.17,32.96 32.04,27.02 24.57,27.02 C16.92,27.02 11.88,32.87 11.88,41.42 C11.88,50.24 17.01,56.27 24.57,56.27 Z', 49.05],
    ];

    /** Build an inline SVG string for the given seed (e.g. crc32 of a document number). */
    public static function inlineSvg(
        int $seed,
        int $width = 700,
        int $height = 80,
        int $spacing = 15,
        int $margin = 4,
        float $strokeWidth = 1,
        string $color = '#1a1a1a',
    ): string {
        $glyphs = self::GLYPHS;
        $count = count($glyphs);

        // Seeded random share of the width per glyph (mirrors the JS "random" mode).
        mt_srand($seed);
        $distribution = [];
        foreach ($glyphs as $key => $glyph) {
            $distribution[$key] = (mt_rand() / mt_getrandmax()) + 0.2;
        }
        $distSum = array_sum($distribution);

        $usable = $width - ($count - 1) * $spacing;
        $posX = 5;
        $uses = '';

        foreach ($glyphs as $key => [$path, $charWidth]) {
            $charSpace = round(($distribution[$key] / $distSum) * $usable);
            $copies = max(1, (int) round(($charSpace - $charWidth) / $margin));

            for ($i = 0; $i < $copies; $i++) {
                $x = round($posX, 2);
                $uses .= "<use href=\"#gl-{$key}\" transform=\"translate({$x},5)\"/>";
                $posX += $margin;
            }
            $posX += $charWidth + ($spacing - $margin);
        }

        $defs = '';
        foreach ($glyphs as $key => [$path]) {
            $defs .= "<path id=\"gl-{$key}\" d=\"{$path}\"/>";
        }

        return '<svg width="100%" viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" '
            . 'fill="none" stroke="' . $color . '" stroke-width="' . $strokeWidth . '" preserveAspectRatio="xMinYMid meet">'
            . "<defs>{$defs}</defs>{$uses}</svg>";
    }
}
