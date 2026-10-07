<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $fillable = [
        'source', 'duplicate_of_id', 'not_duplicate',
        'statement_line_id', 'match_state', 'match_method', 'match_note', 'match_confident', 'numbered_at', 'numbered_from_path', 'auto_match_disabled',
        'original_name', 'filename', 'content_hash', 'original_mime', 'size_bytes', 'local_path',
        'extraction_status', 'extraction_error', 'vendor', 'vendor_domain', 'document_date', 'total_minor', 'currency',
        'amounts', 'invoice_number', 'payment_method', 'confidence', 'extraction', 'text_layer', 'fields_edited',
        'target_year', 'target_month', 'target_edited',
        'filing_status', 'filing_error', 'dropbox_file_id', 'dropbox_path', 'filed_at', 'note',
    ];

    protected $hidden = ['text_layer', 'extraction'];

    protected $casts = [
        'document_date' => 'date',
        'total_minor' => 'integer',
        'size_bytes' => 'integer',
        'amounts' => 'array',
        'extraction' => 'array',
        'fields_edited' => 'boolean',
        'target_year' => 'integer',
        'target_month' => 'integer',
        'target_edited' => 'boolean',
        'filed_at' => 'datetime',
        'match_confident' => 'boolean',
        'not_duplicate' => 'boolean',
        'numbered_at' => 'datetime',
        'auto_match_disabled' => 'boolean',
    ];

    /** The bank or card row that paid this receipt. */
    public function statementLine()
    {
        return $this->belongsTo(StatementLine::class);
    }

    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** Uploaded as an image and converted; such receipts get a generated filename. */
    public function isPhoto(): bool
    {
        return str_starts_with($this->original_mime, 'image/');
    }

    public function isFlagged(): bool
    {
        return $this->extraction_status === 'failed'
            || $this->confidence === 'low'
            // A standing copy (the rent contract) is not read and has no date of its own.
            || ($this->extraction_status === 'done' && $this->document_date === null && $this->source !== 'standing')
            || in_array($this->filing_status, ['failed', 'missing'], true);
    }

    public function scopeNeedsAttention(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->where('extraction_status', 'failed')
            ->orWhere('confidence', 'low')
            ->orWhere(fn (Builder $d) => $d->where('extraction_status', 'done')->whereNull('document_date')->where('source', '!=', 'standing'))
            ->orWhereIn('filing_status', ['failed', 'missing']));
    }

    /**
     * Another receipt that reads as the same document: same vendor, invoice number and
     * total. Vendors generate an invoice PDF anew on every download, so the same invoice
     * fetched twice is rarely the same file byte for byte.
     */
    public function likelyDuplicateOf(): ?self
    {
        if ($this->not_duplicate || blank($this->vendor) || blank($this->invoice_number) || $this->total_minor === null) {
            return null;
        }

        return static::whereKeyNot($this->id)
            ->whereNull('duplicate_of_id')->where('source', '!=', 'standing')
            ->whereRaw('LOWER(vendor) = ?', [mb_strtolower(trim($this->vendor))])
            ->where('invoice_number', $this->invoice_number)
            ->where('total_minor', $this->total_minor)
            ->where(fn ($q) => $q->where('currency', $this->currency)->orWhereNull('currency'))
            ->where('id', '<', $this->id)
            ->orderBy('id')->first();
    }

    /** The website known for a vendor from any of its other receipts. */
    public static function domainForVendor(?string $vendor): ?string
    {
        if (blank($vendor)) {
            return null;
        }

        return static::whereRaw('LOWER(vendor) = ?', [mb_strtolower(trim($vendor))])
            ->whereNotNull('vendor_domain')->latest('updated_at')->value('vendor_domain');
    }

    /**
     * A website set on one receipt holds for the vendor: give it to this vendor's other
     * receipts that have none, or that still carry the value just replaced.
     */
    public function shareVendorDomain(?string $replaced = null): int
    {
        if (blank($this->vendor) || blank($this->vendor_domain)) {
            return 0;
        }

        return static::whereKeyNot($this->id)
            ->whereRaw('LOWER(vendor) = ?', [mb_strtolower(trim($this->vendor))])
            ->where(fn ($q) => $q->whereNull('vendor_domain')->when($replaced, fn ($w) => $w->orWhere('vendor_domain', $replaced)))
            ->update(['vendor_domain' => $this->vendor_domain]);
    }

    /** The "NN" when the file in Dropbox already carries a number prefix, else null. */
    public function numberPrefix(): ?string
    {
        return preg_match('/^(\d{2,3})_/', (string) $this->filename, $m) ? $m[1] : null;
    }
}
