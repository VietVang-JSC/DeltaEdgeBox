<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTimePrice extends Model
{
    use HasFactory;

    protected $table = 'product_time_prices';

    protected $fillable = [
        'id',
        'product_id',
        'store_id',
        'start_time',
        'end_time',
        'price',
        'price_after_tax',
        'priority',
        'days_of_week_mask',
        'start_date',
        'end_date',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'store_id' => 'integer',
        'price' => 'double',
        'price_after_tax' => 'double',
        'priority' => 'integer',
        'days_of_week_mask' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['days_of_week'];

    public function getDaysOfWeekAttribute(): array
    {
        return $this->decodeDayMask($this->days_of_week_mask);
    }

    public function decodeDayMask(?int $mask): array
    {
        if ($mask === null) {
            return [0,1,2,3,4,5,6];
        }

        $days = [];
        for ($day = 0; $day <= 6; $day++) {
            if ($mask & (1 << $day)) {
                $days[] = $day;
            }
        }
        return $days;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
