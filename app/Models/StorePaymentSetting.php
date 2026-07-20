<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePaymentSetting extends Model
{
    protected $fillable = [
        'store_id',
        'type',
        'ref_id',
        'is_show',
        'sort_rank',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'ref_id' => 'integer',
        'is_show' => 'boolean',
        'sort_rank' => 'integer',
    ];
}