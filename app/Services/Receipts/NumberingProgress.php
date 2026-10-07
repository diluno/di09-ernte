<?php

namespace App\Services\Receipts;

use Illuminate\Support\Facades\Cache;

/** How far a month's numbering run has got, for the Bank page to show. */
class NumberingProgress
{
    private const TTL_HOURS = 24;

    public function start(string $month, int $total): void
    {
        Cache::put($this->key($month), ['total' => $total, 'done' => 0, 'skipped' => [], 'started_at' => now()->toIso8601String()], now()->addHours(self::TTL_HOURS));
    }

    public function advance(string $month, ?string $skipped = null): void
    {
        Cache::lock($this->key($month).':lock', 5)->block(5, function () use ($month, $skipped) {
            $state = $this->get($month) ?? ['total' => 0, 'done' => 0, 'skipped' => [], 'started_at' => now()->toIso8601String()];
            $state['done']++;
            if ($skipped !== null) {
                $state['skipped'][] = $skipped;
            }
            unset($state['running']);
            Cache::put($this->key($month), $state, now()->addHours(self::TTL_HOURS));
        });
    }

    /** @return array{total: int, done: int, skipped: list<string>, started_at: string, running: bool}|null */
    public function get(string $month): ?array
    {
        $state = Cache::get($this->key($month));

        return $state ? ['running' => $state['done'] < $state['total']] + $state : null;
    }

    public function isRunning(string $month): bool
    {
        $state = $this->get($month);

        // A run that stopped reporting (worker down) must not block the button for ever.
        return $state !== null && $state['running'] && now()->diffInMinutes($state['started_at'], true) < 30;
    }

    private function key(string $month): string
    {
        return "bank.numbering.{$month}";
    }
}
