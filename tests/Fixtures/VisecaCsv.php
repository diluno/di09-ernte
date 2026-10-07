<?php

namespace Tests\Fixtures;

/** Builds a Viseca transaction export in the shape of the real one (September 2026). */
class VisecaCsv
{
    private const HEADER = 'TransactionId,CardId,Date,ValutaDate,Amount,Currency,OriginalAmount,OriginalCurrency,MerchantName,MerchantPlace,MerchantCountry,StateType,Details,Type,Exchange Rate';

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string, 4?: string, 5?: string}>  $rows
     *   id, "Y-m-d H:i:s", CHF amount, original amount, original currency, merchant
     */
    public static function make(array $rows): string
    {
        $lines = [self::HEADER];
        foreach ($rows as $r) {
            $merchant = $r[5] ?? 'Some Shop';
            $payment = str_starts_with($merchant, 'Ihre Zahlung');
            $lines[] = implode(',', [
                $r[0], $payment ? '' : '547988XXXXXX0000', $r[1], substr($r[1], 0, 10).' 00:00:00',
                $r[2], 'CHF', $r[3] ?? $r[2], $r[4] ?? 'CHF',
                $payment ? '' : $merchant, '', '', 'BOOKED', '"'.strtoupper($merchant).'"', 'merchant', '1.000000',
            ]);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }
}
