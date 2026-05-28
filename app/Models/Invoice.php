<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'project_id',
        'period_start', 'period_end', 'issued_on', 'due_on',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'total_rappen',
        'notes', 'title', 'qr_reference', 'sent_at', 'paid_at', 'pdf_path',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(InvoiceLine::class); }
    public function events() { return $this->hasMany(InvoiceEvent::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }

    public function getOverdueAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    public function scopeOutstanding($q) { return $q->where('status', 'sent'); }
    public function scopePaid($q)        { return $q->where('status', 'paid'); }

    public function getHoursAttribute(): float
    {
        return round((float) $this->lines->sum('hours'), 2);
    }
}
