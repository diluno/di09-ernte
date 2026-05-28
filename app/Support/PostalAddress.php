<?php

namespace App\Support;

class PostalAddress
{
    /**
     * Parse a free-form, often multi-line address string (as Harvest delivers it)
     * into structured Swiss address fields.
     *
     * Best-effort: the last line that looks like "<4–6 digit PLZ> <City>"
     * (optionally prefixed "CH-") becomes postal_code + city, the lines before
     * it become the street lines, and anything after (e.g. a country line) is
     * dropped. With no recognisable PLZ line the whole address is kept in
     * address_line_1 (joined, not mashed) so nothing is lost.
     *
     * @return array{address_line_1: ?string, address_line_2: ?string, postal_code: ?string, city: ?string}
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['address_line_1' => null, 'address_line_2' => null, 'postal_code' => null, 'city' => null];
        }

        $lines = collect(preg_split('/\r\n|\r|\n/', $raw))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->values();

        // Last line shaped like "8004 Zürich" or "CH-8004 Zürich".
        $plzIndex = null;
        $postal = $city = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:[A-Za-z]{2}[-\s])?(\d{4,6})\s+(.+)$/u', $line, $m)) {
                $plzIndex = $i;
                $postal = $m[1];
                $city = trim($m[2]);
            }
        }

        if ($plzIndex === null) {
            return [
                'address_line_1' => self::cut($lines->implode(', ')),
                'address_line_2' => null,
                'postal_code' => null,
                'city' => null,
            ];
        }

        $street = $lines->slice(0, $plzIndex)->values();

        return [
            'address_line_1' => self::cut($street->first()),
            'address_line_2' => $street->count() > 1 ? self::cut($street->slice(1)->implode(', ')) : null,
            'postal_code' => mb_substr($postal, 0, 16),
            'city' => self::cut($city),
        ];
    }

    private static function cut(?string $s): ?string
    {
        return ($s === null || $s === '') ? null : mb_substr($s, 0, 255);
    }
}
