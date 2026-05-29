<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BusinessProfile extends Model
{
    protected $table = 'business_profile';

    protected $fillable = [
        'name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
        'uid', 'vat_id', 'iban', 'qr_iban', 'email', 'logo_path',
        'default_currency', 'default_vat_rate', 'invoice_number_prefix', 'reminder_days_after_due',
    ];

    protected $casts = [
        'default_vat_rate' => 'decimal:2',
        'reminder_days_after_due' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrFail();
    }

    /** Filename-safe form of the business name, e.g. "Müller & Co." -> "Muller-Co". */
    public function filenameSlug(): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii((string) $this->name)), '-');
    }

    /**
     * Build a client-facing PDF filename prefixed with the business name,
     * e.g. documentFilename('Rechnung', '2026-014') -> "Diluno-GmbH-Rechnung-2026-014.pdf".
     */
    public function documentFilename(string $label, string $number): string
    {
        $slug = $this->filenameSlug();
        $prefix = $slug !== '' ? "{$slug}-" : '';

        return "{$prefix}{$label}-{$number}.pdf";
    }
}
