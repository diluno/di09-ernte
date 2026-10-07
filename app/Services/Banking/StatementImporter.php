<?php

namespace App\Services\Banking;

use App\Models\BusinessProfile;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Support\SwissIban;
use Illuminate\Support\Facades\DB;

/** Stores camt.053 statements and their entries exactly once. Matching is a separate step. */
class StatementImporter
{
    public const SOURCE = 'zkb';

    public function __construct(private Camt053Parser $parser) {}

    /**
     * @return array{statements_added: int, statements_known: int, lines_added: int, lines_known: int}
     *
     * @throws CamtException when the file is unreadable or belongs to another account
     */
    public function import(string $xml, string $filename): array
    {
        $statements = $this->parser->parse($xml);

        $ownIban = SwissIban::normalize(BusinessProfile::current()->iban);
        if (! $ownIban) {
            throw new CamtException('Set the business IBAN in Settings before importing statements.');
        }
        foreach ($statements as $statement) {
            if ($statement['account_iban'] !== $ownIban) {
                throw new CamtException("Statement is for account {$statement['account_iban']}, not the business account.");
            }
        }

        $result = ['statements_added' => 0, 'statements_known' => 0, 'lines_added' => 0, 'lines_known' => 0];

        // The bank's file is kept as it came, so a quarter can later be handed on as one file.
        $rawPath = 'statements/'.hash('sha256', $xml).'.xml';
        \Illuminate\Support\Facades\Storage::disk('local')->put($rawPath, $xml);

        DB::transaction(function () use ($statements, $filename, $rawPath, &$result) {
            foreach ($statements as $parsed) {
                $this->refuseFrozenMonths($parsed);

                $known = Statement::where('source', self::SOURCE)
                    ->where('account_iban', $parsed['account_iban'])
                    ->where('statement_ref', $parsed['statement_ref'])
                    ->exists();
                if ($known) {
                    // Imported before originals were kept: uploading the file again supplies it.
                    Statement::where('source', self::SOURCE)->where('account_iban', $parsed['account_iban'])
                        ->where('statement_ref', $parsed['statement_ref'])->whereNull('raw_path')->update(['raw_path' => $rawPath]);
                    $result['statements_known']++;
                    $result['lines_known'] += count($parsed['entries']);

                    continue;
                }

                $entries = $parsed['entries'];
                unset($parsed['entries']);
                $statement = Statement::create($parsed + ['source' => self::SOURCE, 'original_filename' => $filename, 'raw_path' => $rawPath]);
                $result['statements_added']++;

                foreach ($entries as $entry) {
                    if (StatementLine::where('source', self::SOURCE)->where('bank_ref', $entry['bank_ref'])->exists()) {
                        $result['lines_known']++;

                        continue;
                    }
                    StatementLine::create($entry + [
                        'statement_id' => $statement->id,
                        'source' => self::SOURCE,
                        'match_state' => $entry['is_credit'] ? 'unmatched' : null,
                    ]);
                    $result['lines_added']++;
                }
            }
        });

        return $result;
    }

    /**
     * Once files carry a month's numbers, a new entry in that month would shift them.
     * That can only happen if the month was wrongly taken as complete, so it is refused
     * rather than silently renumbered.
     */
    private function refuseFrozenMonths(array $parsed): void
    {
        foreach ($parsed['entries'] as $entry) {
            if (StatementLine::where('source', self::SOURCE)->where('bank_ref', $entry['bank_ref'])->exists()) {
                continue;
            }
            $date = \Illuminate\Support\Carbon::parse($entry['booked_on']);
            $frozen = StatementLine::where('source', self::SOURCE)->whereNotNull('position')
                ->whereYear('booked_on', $date->year)->whereMonth('booked_on', $date->month)->exists();
            if ($frozen) {
                throw new CamtException("Receipts of {$date->format('m/Y')} are already numbered; a new entry on {$entry['booked_on']} would shift the numbers.");
            }
        }
    }

    /**
     * Places where one statement's closing balance is not the next one's opening balance,
     * i.e. statements in between were never imported. Days without movement produce no
     * file, so the balances are the only reliable check.
     *
     * @return list<array{after: string, before: string}>
     */
    public static function gaps(): array
    {
        $gaps = [];
        $statements = Statement::where('source', self::SOURCE)
            ->orderBy('account_iban')->orderBy('to_date')->orderBy('sequence_number')
            ->get(['account_iban', 'from_date', 'to_date', 'opening_balance_rappen', 'closing_balance_rappen']);

        $previous = null;
        foreach ($statements as $statement) {
            if ($previous
                && $previous->account_iban === $statement->account_iban
                && $previous->closing_balance_rappen !== null
                && $statement->opening_balance_rappen !== null
                && $previous->closing_balance_rappen !== $statement->opening_balance_rappen) {
                $gaps[] = [
                    'after' => $previous->to_date->toDateString(),
                    'before' => $statement->from_date->toDateString(),
                ];
            }
            $previous = $statement;
        }

        return $gaps;
    }
}
