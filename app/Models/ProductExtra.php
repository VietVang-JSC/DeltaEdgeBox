<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductExtra extends Model
{
    protected $table = 'product_extras';

    protected $fillable = [
        'store_id',
        'main_product_id',
        'extra_product_id',
        'admin_id',
    ];

    public $timestamps = true;

    public function product()
    {
        return $this->belongsTo(Product::class, 'extra_product_id', 'id');
    }

    public function mainProduct()
    {
        return $this->belongsTo(Product::class, 'main_product_id', 'id');
    }
}
