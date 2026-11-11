<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BusinessProfile extends Model
{
    protected $table = 'business_profile';

    protected $fillable = [
        'name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
        'uid', 'vat_id', 'iban', 'qr_iban', 'email', 'sender_name', 'logo_path',
        'default_currency', 'default_vat_rate', 'invoice_number_prefix', 'reminder_days_after_due',
        'dropbox_refresh_token', 'dropbox_account_label', 'dropbox_connected_at',
    ];

    protected $hidden = ['dropbox_refresh_token'];

    protected $casts = [
        'default_vat_rate' => 'decimal:2',
        'reminder_days_after_due' => 'integer',
        'dropbox_refresh_token' => 'encrypted',
        'dropbox_connected_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::firstOrFail();
    }

    /**
     * The Dropbox refresh token, or null when there is none or it cannot be decrypted. A
     * database restored or copied from another installation carries a token encrypted with
     * that installation's APP_KEY; here it counts as "not connected", so every page still
     * loads and connecting again in Settings simply overwrites it.
     */
    public function dropboxRefreshToken(): ?string
    {
        try {
            return $this->dropbox_refresh_token;
        } catch (DecryptException) {
            // Asked on every page that shows the connection: one line an hour is enough.
            if (Cache::add('dropbox.token-unreadable-warned', true, now()->addHour())) {
                Log::warning('The stored Dropbox refresh token cannot be decrypted with the current APP_KEY; treating Dropbox as not connected. Reconnect it in Settings.');
            }

            return null;
        }
    }

    /** A token is stored, but this installation's APP_KEY cannot read it. */
    public function dropboxTokenUnreadable(): bool
    {
        return filled($this->getRawOriginal('dropbox_refresh_token')) && $this->dropboxRefreshToken() === null;
    }

    /**
     * Clear a token that cannot be decrypted, bypassing the model: Eloquent decrypts the old
     * value to compare it when the attribute changes, so a plain update() would throw.
     */
    public function discardUnreadableDropboxToken(): void
    {
        if (! $this->dropboxTokenUnreadable()) {
            return;
        }
        static::whereKey($this->getKey())->toBase()->update(['dropbox_refresh_token' => null]);
        $this->setRawAttributes(['dropbox_refresh_token' => null] + $this->getAttributes(), true);
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
