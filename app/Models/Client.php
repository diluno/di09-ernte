<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_code',
        'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
        'vat_id', 'default_rate_rappen', 'archived_at',
    ];

    protected $casts = [
        'default_rate_rappen' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Default recipient set as [{name,email}] snapshots. */
    public function defaultRecipients(): array
    {
        return $this->contacts
            ->where('is_default', true)
            ->map(fn (Contact $c) => ['name' => $c->name, 'email' => $c->email])
            ->values()
            ->all();
    }

    public function scopeActive($q) { return $q->whereNull('archived_at'); }
    public function scopeArchived($q) { return $q->whereNotNull('archived_at'); }
}
