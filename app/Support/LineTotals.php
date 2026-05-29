<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total from arrays of line amounts and exempt flags.
     *
     * Exempt lines are included in the subtotal but excluded from the VAT base.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  bool[]  $vatExempts  Parallel exempt flag for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, array $vatExempts, float $vatRate): array
    {
        $lineVatRates = [];
        foreach ($lineAmounts as $i => $_amt) {
            $lineVatRates[$i] = ! empty($vatExempts[$i]) ? 0.0 : $vatRate;
        }

        return self::computeFromRates($lineAmounts, $lineVatRates);
    }

    /**
     * Compute totals from per-line VAT rate snapshots.
     *
     * VAT is rounded per rate bucket, so mixed-rate documents can display a
     * breakdown that exactly matches the stored document total.
     *
     * @param  int[]  $lineAmounts
     * @param  array<int, float|int|string|null>  $lineVatRates
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function computeFromRates(array $lineAmounts, array $lineVatRates): array
    {
        $subtotal = array_sum(array_map('intval', $lineAmounts));
        $vat = array_sum(array_column(self::vatBreakdown($lineAmounts, $lineVatRates), 'vat_rappen'));

        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen' => $vat,
            'total_rappen' => $subtotal + $vat,
        ];
    }

    /**
     * @param  int[]  $lineAmounts
     * @param  array<int, float|int|string|null>  $lineVatRates
     * @return array<int, array{rate: float, base_rappen: int, vat_rappen: int}>
     */
    public static function vatBreakdown(array $lineAmounts, array $lineVatRates): array
    {
        $bases = [];
        foreach ($lineAmounts as $i => $amount) {
            $rate = round((float) ($lineVatRates[$i] ?? 0), 2);
            $key = number_format($rate, 2, '.', '');
            $bases[$key] = ($bases[$key] ?? 0) + (int) $amount;
        }

        ksort($bases, SORT_NUMERIC);

        return collect($bases)
            ->map(fn (int $base, string $rate) => [
                'rate' => (float) $rate,
                'base_rappen' => $base,
                'vat_rappen' => (int) round($base * ((float) $rate) / 100),
            ])
            ->values()
            ->all();
    }
}
