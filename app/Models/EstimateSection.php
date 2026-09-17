<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named group of estimate lines ("Bündel 1 — Regionale Webapp"). Hours and
 * amounts are never stored here; they are summed from the lines.
 */
class EstimateSection extends Model
{
    protected $fillable = ['estimate_id', 'label', 'title', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function estimate()
    {
        return $this->belongsTo(Estimate::class);
    }

    public function lines()
    {
        return $this->hasMany(EstimateLine::class)->orderBy('sort_order');
    }

    /** "Bündel 1 — Regionale Webapp", "Bündel 1" or "Regionale Webapp". */
    public function heading(): string
    {
        return implode(' — ', array_filter([$this->label, $this->title], fn ($v) => filled($v)));
    }
}
