<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'project_id', 'title', 'notes', 'currency', 'vat_rate',
        'cadence', 'anchor_day', 'next_run_on', 'last_generated_on',
        'auto_send', 'paused_at',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'anchor_day' => 'integer',
        'next_run_on' => 'date',
        'last_generated_on' => 'date',
        'auto_send' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(RecurringInvoiceLine::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }

    public function isPaused(): bool { return $this->paused_at !== null; }

    /** Active schedules whose next run is on or before $date. */
    public function scopeDue(Builder $q, $date) { return $q->whereNull('paused_at')->whereDate('next_run_on', '<=', $date); }
}
