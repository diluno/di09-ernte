<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'description', 'hours', 'rate_rappen', 'amount_rappen',
        'vat_exempt', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'vat_exempt' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
