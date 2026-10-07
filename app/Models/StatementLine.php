<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatementLine extends Model
{
    protected $fillable = [
        'statement_id', 'source', 'bank_ref', 'entry_index', 'booked_on', 'value_on',
        'is_credit', 'amount_rappen', 'currency', 'is_reversal', 'is_fee', 'bank_tx_code',
        'description', 'remittance_text', 'creditor_reference', 'counterparty_name', 'transactions',
        'invoice_id', 'match_state', 'match_method', 'match_note',
        'marked_invoice_paid', 'auto_match_disabled', 'note',
        'position', 'transacted_at', 'original_amount_minor', 'original_currency', 'no_receipt',
    ];

    protected $casts = [
        'booked_on' => 'date',
        'value_on' => 'date',
        'is_credit' => 'boolean',
        'is_reversal' => 'boolean',
        'is_fee' => 'boolean',
        'amount_rappen' => 'integer',
        'entry_index' => 'integer',
        'transactions' => 'array',
        'marked_invoice_paid' => 'boolean',
        'auto_match_disabled' => 'boolean',
        'position' => 'integer',
        'transacted_at' => 'datetime',
        'original_amount_minor' => 'integer',
        'no_receipt' => 'boolean',
    ];

    public function statement()
    {
        return $this->belongsTo(Statement::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /** A credit that could be an invoice payment: booked, not reversed, one transaction. */
    public function isMatchable(): bool
    {
        return $this->is_credit && ! $this->is_reversal && $this->transactions === null;
    }
}
