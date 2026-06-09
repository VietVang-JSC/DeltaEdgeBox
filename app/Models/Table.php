<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    protected $table = 'table';

    protected $fillable = [
        'id',
        'store_id',
        'name',
        'tablename',
        'listitem',
        'image',
        'status',
        'user_id',
        'admin_id',
        'is_show',
        'sort_rank',
        'userordered',
        'booking_code',
        'qr_token',
        'lock_time',
        'payment_id',
        'number_of_people',
        'can_order',
        'qr_code',
        'is_order_enabled',
        'pin',
        'qr_code_token',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'status' => 'integer',
        'user_id' => 'integer',
        'payment_id' => 'integer',
        'number_of_people' => 'integer',
        'can_order' => 'integer',
        'is_order_enabled' => 'integer',
        'sort_rank' => 'integer',
        'is_show' => 'integer',
        'admin_id' => 'integer',
        'lock_time' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'id', 'payment_id');
    }
}
