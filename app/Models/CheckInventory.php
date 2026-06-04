<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInventory extends Model
{
    use SoftDeletes;

    protected $table = 'check_inventory';

    protected $fillable = [
        'store_id',
        'inventory_check_code',
        'inventory_check_date',
        'total_discrepancies',
        'increased_discrepancy_quantity',
        'decreased_discrepancy_quantity',
        'check_inventory_status',
        'note',
        'user_init',
        'user_upd',
        'admin_id',
    ];

    protected $with = ['check_inventory_items'];

    /**
     * Get the items in the check inventory sheet
     */
    public function check_inventory_items(): HasMany
    {
        return $this->hasMany(CheckInventoryItems::class, 'check_inventory_id', 'id')->with('products');
    }

    /**
     * Get the user who initialized the sheet
     */
    public function user_init(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_init', 'id')->select('id', 'name');
    }

    /**
     * Get the user who updated the sheet
     */
    public function user_upd(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_upd', 'id')->select('id', 'name');
    }
}
