<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'order_id',
        'user_id',
        'transaction_type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'note',
    ];
 
    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after'  => 'integer',
    ];
 
    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------
 
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
 
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
 
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
 
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
 
    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------
 
    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }
 
    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }
 
    public function scopeDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
        return $query;
    }
}