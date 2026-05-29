<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_invoice_id', 'description', 'hours', 'rate_rappen',
        'vat_exempt', 'vat_code', 'vat_label', 'vat_rate', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'vat_exempt' => 'boolean',
        'vat_rate' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function recurringInvoice()
    {
        return $this->belongsTo(RecurringInvoice::class);
    }
}
