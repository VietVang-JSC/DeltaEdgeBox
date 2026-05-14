<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'product_id',
        'quantity',
        'price',
        'total',
        'note',
    ];

    protected $casts = [
        'payment_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'double',
        'total' => 'double',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
