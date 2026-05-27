<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    public $timestamps = false;

    protected $fillable = ['path', 'size_bytes', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public static function latest(): ?self
    {
        return static::query()->orderByDesc('created_at')->first();
    }
}
