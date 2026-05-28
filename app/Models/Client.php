<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_code', 'contact_name', 'email',
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

    public function scopeActive($q) { return $q->whereNull('archived_at'); }
    public function scopeArchived($q) { return $q->whereNotNull('archived_at'); }
}
