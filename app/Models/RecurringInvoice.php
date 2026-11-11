<?php

namespace App\Models;

use App\Support\BillingPeriod;
use App\Support\DocumentLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RecurringInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'project_id', 'title', 'notes', 'currency', 'vat_rate',
        'cadence', 'anchor_day', 'next_run_on', 'last_generated_on',
        'auto_send', 'paused_at', 'recipients',
    ];

    protected $casts = [
        'recipients' => 'array',
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

    /** The period the next generated invoice will bill: ['start', 'end', 'label']. */
    public function nextPeriod(): ?array
    {
        if (! $this->next_run_on) {
            return null;
        }

        return BillingPeriod::for(
            $this->cadence,
            Carbon::parse($this->next_run_on),
            $this->anchor_day,
            $this->client?->locale ?? DocumentLocale::DEFAULT,
        );
    }

    /**
     * VAT rate the next generated invoice will carry — the rate in force at the
     * end of the period it bills, exactly as RecurringInvoiceGenerator applies it.
     * (The stored `vat_rate` column is a leftover and is not used.)
     */
    public function nextVatRate(): float
    {
        return (float) VatRate::rateForDate($this->nextPeriod()['end'] ?? now());
    }

    public function isPaused(): bool { return $this->paused_at !== null; }

    /** Active schedules whose next run is on or before $date. */
    public function scopeDue(Builder $q, $date) { return $q->whereNull('paused_at')->whereDate('next_run_on', '<=', $date); }
}
