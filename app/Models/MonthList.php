<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthList extends Model
{
    protected $fillable = ['year', 'month', 'dropbox_file_id', 'dropbox_path', 'written_at'];

    protected $casts = ['year' => 'integer', 'month' => 'integer', 'written_at' => 'datetime'];
}
