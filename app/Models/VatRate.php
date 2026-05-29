<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VatRate extends Model
{
    protected $fillable = ['rate', 'valid_from', 'valid_until'];

    protected $casts = [
        'rate' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /**
     * The VAT rate row active on the given date. Falls back to an in-memory row
     * built from the business profile's default when the catalog has no cover.
     */
    public static function forDate(Carbon|string|null $date = null): self
    {
        $day = static::day($date);

        $rate = static::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(function ($query) use ($day) {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day);
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($rate) {
            return $rate;
        }

        $fallback = (float) (BusinessProfile::query()->value('default_vat_rate') ?? 8.10);

        return new self(['rate' => $fallback, 'valid_from' => $day, 'valid_until' => null]);
    }

    /** Convenience: the numeric rate active on the given date. */
    public static function rateForDate(Carbon|string|null $date = null): float
    {
        return (float) static::forDate($date)->rate;
    }

    /** All catalog rows for the editor / client-side totals, oldest first. */
    public static function catalogForFrontend(): Collection
    {
        return static::query()
            ->orderBy('valid_from')
            ->get(['id', 'rate', 'valid_from', 'valid_until']);
    }

    private static function day(Carbon|string|null $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString();
    }
}
