<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $with = ['inventory'];

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
        'is_restricted_time',
        
        // Cloud fields
        'vat',
        'price_after_tax',
        'min_quantity',
        'product_group_id',
        'type_final_product',
        'type_commodity',
        'is_extra',
        'check',
        'second_product_code',
        'third_product_code',
        'type_id',
        'inventory_required',
        'number_of_options',
        'is_ingredient',
        'print_id',
        'sort_rank',
        'is_show',
        'title_vi',
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
        'is_restricted_time' => 'boolean',

        // Cloud casts
        'vat' => 'integer',
        'price_after_tax' => 'double',
        'min_quantity' => 'integer',
        'product_group_id' => 'integer',
        'is_extra' => 'boolean',
        'type_id' => 'integer',
        'inventory_required' => 'boolean',
        'number_of_options' => 'integer',
        'is_ingredient' => 'boolean',
        'print_id' => 'integer',
        'sort_rank' => 'integer',
        'is_show' => 'boolean',
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

    public function timePrices()
    {
        return $this->hasMany(ProductTimePrice::class);
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class, 'product_id', 'id')->select('id', 'product_id', 'quantity');
    }
}
