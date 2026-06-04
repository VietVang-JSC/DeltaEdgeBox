<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInventoryItems extends Model
{
    protected $table = 'check_inventory_items';

    protected $fillable = [
        'store_id',
        'check_inventory_id',
        'product_id',
        'inventory_number',
        'actual_quantity',
        'discrepancy_quantity',
        'admin_id',
    ];

    /**
     * Get the product associated with this item
     */
    public function products(): BelongsTo
    {
        // Use select with aliases to match Cloud BE response keys exactly (product_code, title)
        return $this->belongsTo(Product::class, 'product_id', 'id')
            ->select('id', 'code', 'name', 'code as product_code', 'name as title', 'price', 'image');
    }
}
