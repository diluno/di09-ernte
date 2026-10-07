<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** A bookkeeping quarter, the unit the accountant and the Dropbox folders work in. */
final class Quarter
{
    public function __construct(public readonly int $year, public readonly int $number) {}

    /** "2026-Q3" (also accepts "2026_Q3"); null for anything else. */
    public static function parse(?string $key): ?self
    {
        return preg_match('/^(\d{4})[-_]Q([1-4])$/', (string) $key, $m) ? new self((int) $m[1], (int) $m[2]) : null;
    }

    public static function of(CarbonInterface $date): self
    {
        return new self((int) $date->year, (int) $date->quarter);
    }

    public static function ofMonth(int $year, int $month): self
    {
        return new self($year, intdiv($month - 1, 3) + 1);
    }

    public function key(): string
    {
        return "{$this->year}-Q{$this->number}";
    }

    public function label(): string
    {
        return "Q{$this->number} {$this->year}";
    }

    /** @return list<int> e.g. [7, 8, 9] */
    public function months(): array
    {
        $first = ($this->number - 1) * 3 + 1;

        return [$first, $first + 1, $first + 2];
    }

    public function toArray(): array
    {
        return ['key' => $this->key(), 'label' => $this->label()];
    }
}
