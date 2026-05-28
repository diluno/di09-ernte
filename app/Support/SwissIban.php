<?php

namespace App\Support;

class SwissIban
{
    public static function normalize(?string $iban): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $iban));

        return $normalized !== '' ? $normalized : null;
    }

    public static function isQrIban(?string $iban): bool
    {
        $iid = self::iid($iban);

        return $iid !== null && $iid >= 30000 && $iid <= 31999;
    }

    private static function iid(?string $iban): ?int
    {
        $iban = self::normalize($iban);

        if (! $iban || ! preg_match('/^CH\d{2}(\d{5})/', $iban, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
