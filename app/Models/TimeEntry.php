<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'task_id', 'description',
        'started_at', 'ended_at', 'billable', 'invoice_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'billable' => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function task() { return $this->belongsTo(Task::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }

    public function scopeRunning($q) { return $q->whereNull('ended_at'); }
    public function scopeFinished($q) { return $q->whereNotNull('ended_at'); }
    public function scopeBillable($q) { return $q->where('billable', true); }
    public function scopeUnbilled($q) { return $q->whereNull('invoice_id'); }

    public function getDurationSecondsAttribute(): int
    {
        $endTs = ($this->ended_at ?? now())->getTimestamp();
        return max(0, $endTs - $this->started_at->getTimestamp());
    }

    public function getRunningAttribute(): bool
    {
        return $this->ended_at === null;
    }
}
