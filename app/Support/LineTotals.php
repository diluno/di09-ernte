<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total from arrays of line amounts and exempt flags.
     *
     * Exempt lines are included in the subtotal but excluded from the VAT base.
     *
     * @param  int[]   $lineAmounts  Amount in rappen for each line.
     * @param  bool[]  $vatExempts   Parallel exempt flag for each line.
     * @param  float   $vatRate      VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, array $vatExempts, float $vatRate): array
    {
        $taxable = 0;
        $exempt = 0;
        foreach ($lineAmounts as $i => $amt) {
            if (!empty($vatExempts[$i])) {
                $exempt += $amt;
            } else {
                $taxable += $amt;
            }
        }
        $subtotal = $taxable + $exempt;
        $vat = (int) round($taxable * $vatRate / 100);
        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen'      => $vat,
            'total_rappen'    => $subtotal + $vat,
        ];
    }
}
