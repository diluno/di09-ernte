<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Estimate extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'project_id',
        'issued_on', 'valid_until',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'total_rappen',
        'notes', 'title', 'sent_at', 'decided_at', 'converted_invoice_id', 'pdf_path',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(EstimateLine::class); }
    public function events() { return $this->hasMany(EstimateEvent::class); }
    public function convertedInvoice() { return $this->belongsTo(Invoice::class, 'converted_invoice_id'); }

    public function getExpiredAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function getHoursAttribute(): float
    {
        return round((float) $this->lines->sum('hours'), 2);
    }

    public function scopeOpen($q)     { return $q->where('status', 'sent'); }
    public function scopeAccepted($q) { return $q->where('status', 'accepted'); }
    public function scopeDeclined($q) { return $q->where('status', 'declined'); }

    /** Client-facing PDF filename, e.g. "Diluno-GmbH-Offerte-OF-2026-014.pdf". */
    public function pdfFilename(): string
    {
        return BusinessProfile::current()->documentFilename('Offerte', $this->number);
    }
}
