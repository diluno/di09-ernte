<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VatRate extends Model
{
    protected $fillable = [
        'code', 'label', 'rate', 'valid_from', 'valid_until', 'is_default',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_default' => 'boolean',
    ];

    public static function defaultForDate(Carbon|string|null $date = null): self
    {
        return static::forDate($date, null);
    }

    public static function forDate(Carbon|string|null $date = null, ?string $code = null): self
    {
        $day = static::day($date);

        $query = static::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(function ($query) use ($day) {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day);
            });

        if ($code) {
            $query->where('code', $code);
        } else {
            $query->where('is_default', true);
        }

        $rate = $query->orderByDesc('valid_from')->first();

        if ($rate) {
            return $rate;
        }

        if ($code === 'exempt') {
            return static::fallback('exempt', 'MwSt-befreit', 0.00, $day);
        }

        $fallbackRate = (float) (BusinessProfile::query()->value('default_vat_rate') ?? 8.10);

        return static::fallback($code ?: 'standard', static::labelForCode($code ?: 'standard'), $fallbackRate, $day);
    }

    public static function snapshotFor(?string $code, Carbon|string|null $date = null, ?float $fallbackRate = null): array
    {
        $code = $code ?: 'standard';
        $rate = static::forDate($date, $code);

        if (! $rate->exists && $fallbackRate !== null && $code !== 'exempt') {
            $rate = static::fallback($code, static::labelForCode($code), $fallbackRate, static::day($date));
        }

        return [
            'vat_code' => $rate->code,
            'vat_label' => $rate->label,
            'vat_rate' => (float) $rate->rate,
            'vat_exempt' => (float) $rate->rate === 0.0,
        ];
    }

    public static function catalogForFrontend(): Collection
    {
        return static::query()
            ->orderByRaw("FIELD(code, 'standard', 'reduced', 'special', 'exempt')")
            ->orderBy('valid_from')
            ->get(['code', 'label', 'rate', 'valid_from', 'valid_until', 'is_default']);
    }

    public static function optionsForDate(Carbon|string|null $date = null): Collection
    {
        $day = static::day($date);

        return static::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(function ($query) use ($day) {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day);
            })
            ->orderByDesc('is_default')
            ->orderByRaw("FIELD(code, 'standard', 'reduced', 'special', 'exempt')")
            ->get(['code', 'label', 'rate', 'valid_from', 'valid_until', 'is_default']);
    }

    private static function day(Carbon|string|null $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString();
    }

    private static function fallback(string $code, string $label, float $rate, string $day): self
    {
        return new self([
            'code' => $code,
            'label' => $label,
            'rate' => $rate,
            'valid_from' => $day,
            'valid_until' => null,
            'is_default' => $code === 'standard',
        ]);
    }

    private static function labelForCode(string $code): string
    {
        return match ($code) {
            'exempt' => 'MwSt-befreit',
            'reduced' => 'Reduzierter Satz',
            'special' => 'Sondersatz Beherbergung',
            default => 'Normalsatz',
        };
    }
}
