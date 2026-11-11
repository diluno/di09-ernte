<?php

namespace App\Models;

use App\Models\Concerns\HasRecipients;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Estimate extends Model
{
    use HasFactory;
    use HasRecipients;

    /** New documents default to the client's language; set explicitly to override. */
    protected static function booted(): void
    {
        static::creating(function (self $doc) {
            $doc->locale ??= $doc->client?->locale ?? \App\Support\DocumentLocale::DEFAULT;
        });
    }

    protected $fillable = [
        'number', 'client_id', 'locale', 'project_id',
        'issued_on', 'valid_until',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'rounding_rappen', 'total_rappen',
        'notes', 'assumptions', 'title', 'sent_at', 'decided_at', 'converted_invoice_id', 'pdf_path', 'recipients',
    ];

    protected $casts = [
        'recipients' => 'array',
        'assumptions' => 'array',
        'issued_on' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'rounding_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(EstimateLine::class); }
    public function sections() { return $this->hasMany(EstimateSection::class)->orderBy('sort_order'); }
    public function events() { return $this->hasMany(EstimateEvent::class); }
    public function convertedInvoice() { return $this->belongsTo(Invoice::class, 'converted_invoice_id'); }

    public function getExpiredAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->valid_until !== null
            // Valid through the whole of its last day (same rule as the Expired filter).
            && $this->valid_until->lt(now()->startOfDay());
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
        return BusinessProfile::current()->documentFilename(__('documents.estimate', [], $this->locale ?? \App\Support\DocumentLocale::DEFAULT), $this->number);
    }
}
