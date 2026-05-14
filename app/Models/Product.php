<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'category_id',
        'code',
        'name',
        'description',
        'image',
        'price',
        'sale_price',
        'quantity',
        'unit',
        'status',
        'is_combo',
        'admin_id',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'category_id' => 'integer',
        'price' => 'double',
        'sale_price' => 'double',
        'quantity' => 'integer',
        'unit' => 'integer',
        'status' => 'boolean',
        'is_combo' => 'boolean',
        'admin_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function paymentDetails()
    {
        return $this->hasMany(PaymentDetail::class);
    }
}
