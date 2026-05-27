<?php

namespace App\Services\Invoicing;

class QrReferenceGenerator
{
    /**
     * Mod-10 recursive lookup table (Swiss QR-bill standard, "Modulo 10 rekursiv").
     */
    private const TABLE = [
        [0, 9, 4, 6, 8, 2, 7, 1, 3, 5],
        [9, 4, 6, 8, 2, 7, 1, 3, 5, 0],
        [4, 6, 8, 2, 7, 1, 3, 5, 0, 9],
        [6, 8, 2, 7, 1, 3, 5, 0, 9, 4],
        [8, 2, 7, 1, 3, 5, 0, 9, 4, 6],
        [2, 7, 1, 3, 5, 0, 9, 4, 6, 8],
        [7, 1, 3, 5, 0, 9, 4, 6, 8, 2],
        [1, 3, 5, 0, 9, 4, 6, 8, 2, 7],
        [3, 5, 0, 9, 4, 6, 8, 2, 7, 1],
        [5, 0, 9, 4, 6, 8, 2, 7, 1, 3],
    ];

    /**
     * Build a 27-digit QRR for the given invoice id.
     *
     * Layout (left-to-right):
     *   - 20 digits of zero padding (placeholder for bank/customer prefix in later phases)
     *   - 6 digits of zero-padded invoice id
     *   - 1 mod-10 recursive check digit
     */
    public function generate(int $invoiceId): string
    {
        $payload = str_pad((string) $invoiceId, 26, '0', STR_PAD_LEFT);
        return $payload . $this->checkDigit($payload);
    }

    /**
     * Compute the mod-10 recursive check digit for a numeric string.
     */
    public function checkDigit(string $digits): int
    {
        $carry = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            $carry = self::TABLE[$carry][$d];
        }
        return (10 - $carry) % 10;
    }
}
