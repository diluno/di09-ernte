<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total. Every line is taxed at the single
     * document rate.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, float $vatRate): array
    {
        $subtotal = array_sum(array_map('intval', $lineAmounts));
        $vat = (int) round($subtotal * $vatRate / 100);

        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen' => $vat,
            'total_rappen' => $subtotal + $vat,
        ];
    }
}
