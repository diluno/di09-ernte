<?php

namespace App\Services\Banking;

use App\Models\Statement;
use App\Models\StatementLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The accountant's row numbers (the "NN" of a filename).
 *
 * Bank: position within the booking month in the bank's own order, every entry counted.
 * Card: position within the bill by transaction time, oldest first; credits not counted.
 */
class StatementPositions
{
    private bool $memoise = false;

    /** @var list<array{after: string, before: string}>|null */
    private ?array $gaps = null;

    /** @var array<string, bool> */
    private array $complete = [];

    /**
     * Keep the answers about gaps and complete months for the life of this instance. Only
     * for one page render: an import in between would make them stale.
     */
    public function memoised(): static
    {
        $this->memoise = true;

        return $this;
    }

    /** @return list<array{after: string, before: string}> */
    public function gaps(): array
    {
        if (! $this->memoise) {
            return StatementImporter::gaps();
        }

        return $this->gaps ??= StatementImporter::gaps();
    }

    /**
     * Bank entries of a month, in order, keyed by line id => number.
     *
     * @return Collection<int, StatementLine> with a transient ->number
     */
    public function bankMonth(int $year, int $month): Collection
    {
        $lines = StatementLine::query()
            ->join('statements', 'statements.id', '=', 'statement_lines.statement_id')
            ->where('statement_lines.source', StatementImporter::SOURCE)
            ->whereBetween('statement_lines.booked_on', self::monthRange($year, $month))
            ->orderBy('statement_lines.booked_on')->orderBy('statements.sequence_number')->orderBy('statement_lines.entry_index')
            ->select('statement_lines.*')->get();

        return $lines->values()->each(fn (StatementLine $l, int $i) => $l->number = $l->position ?? $i + 1);
    }

    /** @return Collection<int, StatementLine> with ->number (null for credits) */
    public function bill(Statement $bill): Collection
    {
        $n = 0;

        return $bill->lines()->orderBy('transacted_at')->orderBy('bank_ref')->get()->values()
            ->each(function (StatementLine $l) use (&$n) {
                $l->number = $l->is_credit ? null : ($l->position ?? ++$n);
                if (! $l->is_credit && $l->position !== null) {
                    $n = $l->position;
                }
            });
    }

    /**
     * A bank month's numbers are final once a statement from after the month exists and
     * no balance gap falls inside it: only then can no earlier row still turn up.
     */
    public function bankMonthComplete(int $year, int $month): bool
    {
        if (! $this->memoise) {
            return $this->monthComplete($year, $month);
        }

        return $this->complete["{$year}-{$month}"] ??= $this->monthComplete($year, $month);
    }

    /**
     * First and last day of a month as date strings, for a range the index on booked_on can serve.
     *
     * @return array{0: string, 1: string}
     */
    public static function monthRange(int $year, int $month, ?int $throughMonth = null): array
    {
        return [
            Carbon::create($year, $month, 1)->toDateString(),
            Carbon::create($year, $throughMonth ?? $month, 1)->endOfMonth()->toDateString(),
        ];
    }

    private function monthComplete(int $year, int $month): bool
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $later = Statement::where('source', StatementImporter::SOURCE)->whereDate('to_date', '>', $end)->exists();
        if (! $later) {
            return false;
        }

        foreach ($this->gaps() as $gap) {
            // Statements are missing strictly between these two dates.
            if (Carbon::parse($gap['after'])->lt($end) && Carbon::parse($gap['before'])->gt($start)) {
                return false;
            }
        }

        return true;
    }

    /** "07", "112": two digits, three from 100. */
    public static function prefix(int $number): string
    {
        return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }
}
