<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'name', 'code', 'description', 'glyph', 'status',
        'billable', 'retainer', 'retainer_hours', 'retainer_resets_monthly',
        'budget_hours', 'budget_amount_rappen', 'rate_rappen',
        'started_on', 'deadline_on',
    ];

    protected $casts = [
        'billable' => 'boolean',
        'retainer' => 'boolean',
        'retainer_resets_monthly' => 'boolean',
        'budget_hours' => 'integer',
        'budget_amount_rappen' => 'integer',
        'rate_rappen' => 'integer',
        'started_on' => 'date',
        'deadline_on' => 'date',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function tasks() { return $this->hasMany(Task::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }

    public function scopeActive($q) { return $q->where('status', 'active'); }
    public function scopeArchived($q) { return $q->where('status', 'archived'); }

    public function spentHours(): float
    {
        $seconds = (int) $this->timeEntries()
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS seconds')
            ->value('seconds');
        return round($seconds / 3600, 2);
    }

    public function spentAmountRappen(): int
    {
        return (int) round($this->spentHours() * $this->rate_rappen);
    }

    public function percentHours(): int
    {
        if ($this->budget_hours <= 0) return 0;
        return (int) round(($this->spentHours() / $this->budget_hours) * 100);
    }

    protected function band(): Attribute
    {
        return Attribute::get(function () {
            $p = $this->percentHours();
            if ($p > 100) return 'over';
            if ($p >= 85) return 'warn';
            return 'ok';
        });
    }
}
