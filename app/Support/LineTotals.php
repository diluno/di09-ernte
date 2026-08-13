<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total. Lines are taxed at the single document
     * rate unless flagged VAT-exempt.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @param  bool[]  $exemptFlags  Parallel to $lineAmounts; true = no VAT on that line.
     * @return array{subtotal_rappen: int, vat_rappen: int, rounding_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, float $vatRate, array $exemptFlags = []): array
    {
        $amounts = array_values(array_map('intval', $lineAmounts));
        $flags = array_values($exemptFlags);
        $subtotal = array_sum($amounts);
        $vatBase = 0;
        foreach ($amounts as $i => $amount) {
            if (empty($flags[$i])) {
                $vatBase += $amount;
            }
        }
        $vat = (int) round($vatBase * $vatRate / 100);
        $exact = $subtotal + $vat;
        $total = (int) (round($exact / 5) * 5);

        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen' => $vat,
            'rounding_rappen' => $total - $exact,
            'total_rappen' => $total,
        ];
    }
}
