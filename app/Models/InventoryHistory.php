<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryHistory extends Model
{
    protected $table = 'inventory_histories';

    protected $fillable = [
        'store_id',
        'product_id',
        'input_id',
        'input_code',
        'input_date',
        'quantity',
        'admin_id',
    ];

    protected $with = ['product'];

    /**
     * Get the product that owns the InventoryHistory
     */
    public function product(): BelongsTo
    {
        // Use select with aliases to match Cloud BE response keys exactly (product_code, title)
        return $this->belongsTo(Product::class, 'product_id', 'id')
            ->select('id', 'code', 'name', 'code as product_code', 'name as title', 'price', 'image');
    }

    /**
     * Get the store that owns the InventoryHistory
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }
}
