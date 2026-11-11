<?php

namespace App\Models;

use App\Models\Concerns\HasRecipients;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
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
        'number', 'client_id', 'locale', 'project_id', 'recurring_invoice_id', 'recurring_occurrence_on',
        'period_start', 'period_end', 'issued_on', 'due_on',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'rounding_rappen', 'total_rappen',
        'notes', 'title', 'qr_reference', 'sent_at', 'paid_at', 'reminders_paused_at', 'pdf_path', 'recipients',
        'dropbox_file_id', 'dropbox_path',
    ];

    protected $casts = [
        'recipients' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'recurring_occurrence_on' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'reminders_paused_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'rounding_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function recurringInvoice()
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function events()
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    public function timeEntries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** Bank entries matched (or proposed) as payments of this invoice. */
    public function bankEntries()
    {
        return $this->hasMany(StatementLine::class);
    }

    public function getOverdueAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Only invoices that were never issued may be deleted: drafts, and drafts
     * that were voided before being sent. Anything a client has received stays
     * on file (void it instead) — deleting would drop the frozen PDF and hand
     * its billed time back for re-billing.
     */
    public function isDeletable(): bool
    {
        return $this->status === 'draft'
            || ($this->status === 'void' && $this->sent_at === null);
    }

    public function scopeOutstanding($q)
    {
        return $q->where('status', 'sent');
    }

    public function scopePaid($q)
    {
        return $q->where('status', 'paid');
    }

    public function getHoursAttribute(): float
    {
        return round((float) $this->lines->sum('hours'), 2);
    }

    /** Client-facing PDF filename, e.g. "Diluno-GmbH-Rechnung-2026-014.pdf". */
    public function pdfFilename(): string
    {
        return BusinessProfile::current()->documentFilename(__('documents.invoice', [], $this->locale ?? \App\Support\DocumentLocale::DEFAULT), $this->number);
    }
}
