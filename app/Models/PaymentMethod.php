<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'value',
        'name',
        'store_id',
    ];

    protected $casts = [
        'value' => 'integer',
        'store_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'payment_method', 'value');
    }
}
