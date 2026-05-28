<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimateEvent extends Model
{
    protected $fillable = ['estimate_id', 'kind', 'occurred_at', 'payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function estimate() { return $this->belongsTo(Estimate::class); }
}
