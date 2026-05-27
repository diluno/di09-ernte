<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
