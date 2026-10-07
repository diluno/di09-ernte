<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuarterFile extends Model
{
    protected $fillable = ['year', 'quarter', 'kind', 'dropbox_file_id', 'dropbox_path', 'written_at'];

    protected $casts = ['year' => 'integer', 'quarter' => 'integer', 'written_at' => 'datetime'];
}
