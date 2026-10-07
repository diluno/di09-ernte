<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Statement extends Model
{
    protected $fillable = [
        'source', 'account_iban', 'message_id', 'statement_ref', 'sequence_number',
        'from_date', 'to_date', 'opening_balance_rappen', 'closing_balance_rappen', 'original_filename',
        'bank_line_id', 'charges_rappen',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'sequence_number' => 'integer',
        'opening_balance_rappen' => 'integer',
        'closing_balance_rappen' => 'integer',
        'charges_rappen' => 'integer',
    ];

    /** Card bills only: the direct debit on the bank account that paid this bill. */
    public function bankLine()
    {
        return $this->belongsTo(StatementLine::class, 'bank_line_id');
    }

    public function lines()
    {
        return $this->hasMany(StatementLine::class);
    }
}
