<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A document that pays the same row every month (the rent contract), copied in numbered. */
class StandingDocument extends Model
{
    protected $fillable = ['label', 'row_keyword', 'source_path', 'filename', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function fits(StatementLine $row): bool
    {
        $haystack = mb_strtolower("{$row->counterparty_name} {$row->description} {$row->remittance_text}");

        return $this->active && $this->row_keyword !== '' && str_contains($haystack, mb_strtolower($this->row_keyword));
    }
}
