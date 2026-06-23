<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';

    protected $fillable = [
        'store_id',
        'booking_code',
        'user_id',
        'admin_id',
        'time_arrival',
        'status',
        'customer_id',
        'table_id',
        'note',
        'total_customer',
        'item_list',
        'use_time',
    ];

    protected $casts = [
        'time_arrival' => 'datetime',
        'use_time'     => 'float',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
