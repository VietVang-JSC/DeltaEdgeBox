<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'capacity',
        'status',
        'note',
        'admin_id',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'capacity' => 'integer',
        'status' => 'integer',
        'admin_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
