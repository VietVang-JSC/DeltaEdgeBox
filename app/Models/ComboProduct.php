<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComboProduct extends Model
{
    protected $table = 'combo_product';

    protected $fillable = [
        'store_id',
        'combo_product_id',
        'product_id',
        'is_required',
        'quantity',
        'admin_id',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'quantity'    => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────────

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function comboProduct(): BelongsTo
    {
        return $this->belongsTo(ComboProduct::class, 'combo_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
    public function comboProductDetails()
    {
        return $this->hasMany(ComboProductDetail::class, 'combo_product_id');
    }

    // get all products in a combo product
    public function products()
    {
        return $this->belongsToMany(Product::class, 'combo_product', 'combo_product_id', 'product_id')
                    ->withPivot('is_required', 'quantity')
                    ->withTimestamps();
    }
}
