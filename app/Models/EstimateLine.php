<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstimateLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id', 'description', 'hours', 'rate_rappen', 'amount_rappen', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'sort_order' => 'integer',
    ];

    public function estimate()
    {
        return $this->belongsTo(Estimate::class);
    }
}
