<?php
// app/Models/Contact.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'name', 'email', 'role', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
}
