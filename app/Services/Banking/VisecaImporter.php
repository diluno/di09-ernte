<?php

namespace App\Services\Banking;

use App\Models\Statement;
use App\Models\StatementLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Card transactions from Viseca's CSV export. An export can cover any period and overlap
 * earlier ones, so rows first land in a pool; bills are then cut out of the pool wherever
 * a run of rows adds up exactly to a Viseca direct debit on the bank account.
 */
class VisecaImporter
{
    public const SOURCE = 'viseca';

    private const POOL_REF = 'pool';

    private const REQUIRED = ['TransactionId', 'Date', 'Amount', 'Currency', 'OriginalAmount', 'OriginalCurrency', 'Details'];

    /**
     * @return array{lines_added: int, lines_known: int, bills_assigned: int}
     *
     * @throws CamtException when the file is not a Viseca transaction export
     */
    public function import(string $csv, string $filename): array
    {
        $rows = $this->parse($csv);
        $result = ['lines_added' => 0, 'lines_known' => 0, 'bills_assigned' => 0];

        DB::transaction(function () use ($rows, $filename, &$result) {
            $pool = $this->pool($filename);
            foreach ($rows as $row) {
                if (StatementLine::where('source', self::SOURCE)->where('bank_ref', $row['bank_ref'])->exists()) {
                    $result['lines_known']++;

                    continue;
                }
                StatementLine::create($row + ['statement_id' => $pool->id, 'source' => self::SOURCE]);
                $result['lines_added']++;
            }
        });

        $result['bills_assigned'] = $this->assignBills();

        return $result;
    }

    /** True when the upload looks like this export rather than a camt file. */
    public static function looksLikeCsv(string $contents): bool
    {
        return str_contains(substr($contents, 0, 400), 'TransactionId');
    }

    /**
     * Cut bills out of the pool. Returns how many were assigned. Safe to call any time:
     * after a card import, and after a bank import that may have brought the debit.
     */
    public function assignBills(): int
    {
        return DB::transaction(function () {
            $pool = Statement::where('source', self::SOURCE)->where('statement_ref', self::POOL_REF)->first();
            if (! $pool) {
                return 0;
            }

            $billed = Statement::where('source', self::SOURCE)->whereNotNull('bank_line_id')->pluck('bank_line_id');
            $debits = StatementLine::where('source', StatementImporter::SOURCE)
                ->where('is_credit', false)->where('is_reversal', false)
                ->where('counterparty_name', 'like', '%Viseca%')
                ->whereNotIn('id', $billed)
                ->orderBy('booked_on')->orderBy('id')->get();

            $assigned = 0;
            foreach ($debits as $debit) {
                $rows = $pool->lines()->where('bank_tx_code', '!=', 'PAYMENT')
                    ->whereDate('transacted_at', '<=', $debit->booked_on)
                    ->orderBy('transacted_at')->orderBy('bank_ref')->get()->values();

                $run = $this->runSummingTo($rows, $debit->amount_rappen);
                if ($run === null) {
                    continue;
                }

                $bill = Statement::create([
                    'source' => self::SOURCE, 'account_iban' => $pool->account_iban, 'message_id' => 'bill',
                    'statement_ref' => $debit->bank_ref, 'bank_line_id' => $debit->id, 'charges_rappen' => $debit->amount_rappen,
                    'from_date' => $run->first()->booked_on, 'to_date' => $run->last()->booked_on,
                    'original_filename' => $pool->original_filename,
                ]);
                StatementLine::whereIn('id', $run->pluck('id'))->update(['statement_id' => $bill->id]);
                $assigned++;
            }

            return $assigned;
        });
    }

    /** Put a bill's rows back into the pool, should an assignment look wrong. */
    public function dissolve(Statement $bill): void
    {
        if ($bill->source !== self::SOURCE || $bill->bank_line_id === null) {
            throw new \DomainException('Only a card bill can be dissolved.');
        }
        if ($bill->lines()->whereNotNull('position')->exists()) {
            throw new \DomainException('This bill already has frozen numbers and cannot be dissolved.');
        }

        DB::transaction(function () use ($bill) {
            $bill->lines()->update(['statement_id' => $this->pool($bill->original_filename)->id]);
            $bill->delete();
        });
    }

    /**
     * The contiguous run, ending as late as possible, whose signed amounts sum to $target.
     *
     * @param  Collection<int, StatementLine>  $rows  in date order
     * @return Collection<int, StatementLine>|null
     */
    private function runSummingTo(Collection $rows, int $target): ?Collection
    {
        for ($end = $rows->count() - 1; $end >= 0; $end--) {
            $sum = 0;
            for ($start = $end; $start >= 0; $start--) {
                $row = $rows[$start];
                $sum += $row->is_credit ? -$row->amount_rappen : $row->amount_rappen;
                // A run never starts on a credit: the credit belongs with the charges before it.
                if ($sum === $target && ! $row->is_credit) {
                    return $rows->slice($start, $end - $start + 1)->values();
                }
            }
        }

        return null;
    }

    private function pool(string $filename): Statement
    {
        return Statement::firstOrCreate(
            ['source' => self::SOURCE, 'statement_ref' => self::POOL_REF, 'account_iban' => 'VISECA'],
            ['message_id' => 'pool', 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(), 'original_filename' => $filename],
        );
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $header = str_getcsv(array_shift($lines) ?? '', ',', '"', '');
        if (array_diff(self::REQUIRED, $header) !== []) {
            throw new CamtException('Not a Viseca transaction export (CSV with TransactionId, Date, Amount, … columns).');
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '');
            if (count($values) !== count($header)) {
                throw new CamtException('Row '.($index + 2).' of the CSV has the wrong number of columns.');
            }
            $r = array_combine($header, $values);
            if (($r['StateType'] ?? 'BOOKED') !== 'BOOKED') {
                continue;
            }
            if ($r['Currency'] !== 'CHF') {
                throw new CamtException("Row {$r['TransactionId']} is billed in {$r['Currency']}; only CHF card bills are supported.");
            }

            $amount = self::minor($r['Amount']);
            $original = self::minor($r['OriginalAmount']);
            try {
                $at = Carbon::parse($r['Date']);
            } catch (\Throwable) {
                throw new CamtException("Row {$r['TransactionId']} has an unreadable date.");
            }
            $isPayment = $amount < 0 && preg_match('/^Ihre Zahlung/i', trim($r['Details'])) === 1;

            $rows[] = [
                'bank_ref' => trim($r['TransactionId']),
                'entry_index' => $index,
                'booked_on' => $at->toDateString(),
                'transacted_at' => $at,
                'value_on' => filled($r['ValutaDate'] ?? null) ? substr($r['ValutaDate'], 0, 10) : null,
                'is_credit' => $amount < 0,
                'amount_rappen' => abs($amount),
                'currency' => 'CHF',
                'original_amount_minor' => abs($original),
                'original_currency' => strtoupper(trim($r['OriginalCurrency'])) ?: 'CHF',
                // The export's own "Type" is unreliable (a purchase can be typed "fee"), so it is not used.
                'bank_tx_code' => $isPayment ? 'PAYMENT' : 'CARD',
                'description' => trim($r['Details']),
                'counterparty_name' => trim($r['MerchantName'] ?? '') !== '' ? trim($r['MerchantName']) : trim($r['Details']),
            ];
        }

        return $rows;
    }

    /** "16.200" -> 1620, "-912.650" -> -91265. Viseca writes three decimals. */
    private static function minor(string $amount): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $m)) {
            throw new CamtException("Unreadable amount: {$amount}");
        }
        $cents = (int) $m[2] * 100 + (int) round((float) ('0.'.($m[3] ?? '0')) * 100);

        return $m[1] === '-' ? -$cents : $cents;
    }
}
