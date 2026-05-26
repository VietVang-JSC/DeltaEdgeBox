<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Inventory extends Model
{
    protected $table = 'inventory';
 
    protected $fillable = [
        'store_id',
        'product_id',
        'quantity',
        'reserved_quantity',
        'low_stock_threshold',
        'last_restocked_at',
    ];
 
    protected $casts = [
        'last_restocked_at' => 'datetime',
    ];
 
    // Relationships
  
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
 
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
 
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'product_id', 'product_id')
            ->where('store_id', $this->store_id);
    }
 
    // Business Logic Methods
   
    /**
     * Reserve inventory when order is confirmed.
     * Uses pessimistic locking to prevent race conditions.
     */
    public function reserveQuantity(int $qty, ?int $orderId = null): bool
    {
        return DB::transaction(function () use ($qty, $orderId) {
            $inventory = self::lockForUpdate()->find($this->id);
 
            $available = $inventory->quantity - $inventory->reserved_quantity;
            if ($available < $qty) {
                throw new \Exception("Inventory insufficient: {$available}, needed: {$qty}");
            }
 
            $before = $inventory->reserved_quantity;
            $inventory->reserved_quantity += $qty;
            $inventory->save();
 
            InventoryTransaction::create([
                'store_id'        => $inventory->store_id,
                'product_id'      => $inventory->product_id,
                'order_id'        => $orderId,
                'user_id'         => Auth::id(),
                'transaction_type'=> 'reservation',
                'quantity_change' => $qty,
                'quantity_before' => $before,
                'quantity_after'  => $inventory->reserved_quantity,
                'note'            => "Reserve {$qty} product(s) for order #{$orderId}",
            ]);
 
            return true;
        });
    }
 
    /**
     *  order cancelled.
     */
    public function releaseReservation(int $qty, ?int $orderId = null): bool
    {
        return DB::transaction(function () use ($qty, $orderId) {
            $inventory = self::lockForUpdate()->find($this->id);
 
            if ($inventory->reserved_quantity < $qty) {
                $qty = $inventory->reserved_quantity; 
            }
 
            $before = $inventory->reserved_quantity;
            $inventory->reserved_quantity -= $qty;
            $inventory->save();
 
            InventoryTransaction::create([
                'store_id'        => $inventory->store_id,
                'product_id'      => $inventory->product_id,
                'order_id'        => $orderId,
                'user_id'         => Auth::id(),
                'transaction_type'=> 'release',
                'quantity_change' => -$qty,
                'quantity_before' => $before,
                'quantity_after'  => $inventory->reserved_quantity,
                'note'            => "Cancel {$qty} product(s) for order #{$orderId}",
            ]);
 
            return true;
        });
    }
 
    /**
     * instantly deduct stock when order is completed (paid).
     * Load release reservation .
     */
    public function deductStock(int $qty, ?int $orderId = null): bool
    {
        return DB::transaction(function () use ($qty, $orderId) {
            $inventory = self::lockForUpdate()->find($this->id);
 
            if ($inventory->quantity < $qty) {
                throw new \Exception("Inventory insufficient: {$inventory->quantity}, needed: {$qty}");
            }
 
            $beforeQty      = $inventory->quantity;
            $beforeReserved = $inventory->reserved_quantity;
 
            $inventory->quantity          -= $qty;
            $inventory->reserved_quantity  = max(0, $inventory->reserved_quantity - $qty);
            $inventory->save();
 
            InventoryTransaction::create([
                'store_id'        => $inventory->store_id,
                'product_id'      => $inventory->product_id,
                'order_id'        => $orderId,
                'user_id'         => Auth::id(),
                'transaction_type'=> 'sale',
                'quantity_change' => -$qty,
                'quantity_before' => $beforeQty,
                'quantity_after'  => $inventory->quantity,
                'note'            => "Sales Issue {$qty} Order line items #{$orderId}",
            ]);
 
            return true;
        });
    }
 
    /**
     * restock product
     */
    public function restock(int $qty, ?string $note = null): bool
    {
        return DB::transaction(function () use ($qty, $note) {
            $inventory = self::lockForUpdate()->find($this->id);
 
            $before = $inventory->quantity;
            $inventory->quantity          += $qty;
            $inventory->last_restocked_at  = now();
            $inventory->save();
 
            InventoryTransaction::create([
                'store_id'        => $inventory->store_id,
                'product_id'      => $inventory->product_id,
                'user_id'         => Auth::id(),
                'transaction_type'=> 'restock',
                'quantity_change' => $qty,
                'quantity_before' => $before,
                'quantity_after'  => $inventory->quantity,
                'note'            => $note ?? "Nhập kho {$qty} sản phẩm",
            ]);
 
            return true;
        });
    }
 
    /**
     * Check if stock is low based on the threshold.
     */
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->low_stock_threshold;
    }
 
    /**
     * Số lượng thực tế có thể bán (trừ đi đã giữ chỗ).
     */
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }
}
