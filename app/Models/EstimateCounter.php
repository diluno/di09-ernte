<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimateCounter extends Model
{
    protected $primaryKey = 'year';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['year', 'last_n'];
    protected $casts = ['year' => 'integer', 'last_n' => 'integer'];
}
