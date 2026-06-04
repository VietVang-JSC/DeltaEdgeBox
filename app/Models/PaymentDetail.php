<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentDetail extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_id',
        'product_id',
        'product_key',
        'quantity',
        'price',
        'total',
        'note',
        'product_extra',
        'optional_products',
        'inventory_histories',
        'input_code',
        'admin_id',
        'store_id',
        'debt',
        'status',
        'delete_note',
        'detail_discount',
        'served',
        'tax_amount',
        'detail_discount_excluding_tax',
        'unit_price_excluding_tax',
        'discounted_price_excluding_tax',
        'printed_quantity',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'payment_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'double',
        'total' => 'double',
        'admin_id' => 'integer',
        'store_id' => 'integer',
        'debt' => 'double',
        'status' => 'integer',
        'detail_discount' => 'double',
        'served' => 'boolean',
        'tax_amount' => 'double',
        'detail_discount_excluding_tax' => 'double',
        'unit_price_excluding_tax' => 'double',
        'discounted_price_excluding_tax' => 'double',
        'printed_quantity' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
