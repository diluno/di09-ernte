<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstimateLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id', 'estimate_section_id', 'title', 'description', 'hours', 'rate_rappen', 'amount_rappen', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'sort_order' => 'integer',
        'estimate_section_id' => 'integer',
    ];

    public function estimate()
    {
        return $this->belongsTo(Estimate::class);
    }

    public function section()
    {
        return $this->belongsTo(EstimateSection::class, 'estimate_section_id');
    }

    /**
     * Title and description as one plain block, for places that only have a
     * single text field (converted invoice lines, the drafting prompt).
     * Legacy lines have no title and return their description unchanged.
     */
    public function combinedDescription(): string
    {
        if (blank($this->title)) {
            return (string) $this->description;
        }

        return blank($this->description)
            ? (string) $this->title
            : "**{$this->title}**\n{$this->description}";
    }
}
