<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'budget_hours', 'done', 'sort_order'];

    protected $casts = [
        'budget_hours' => 'integer',
        'done' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function project() { return $this->belongsTo(Project::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }
}
