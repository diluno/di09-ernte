<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceEvent extends Model
{
    protected $fillable = ['invoice_id', 'kind', 'occurred_at', 'payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
