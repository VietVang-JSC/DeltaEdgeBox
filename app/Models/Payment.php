<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'table_id',
        'customer_id',
        'paid_date',
        'total',
        'discount',
        'tax',
        'final_total',
        'payment_method',
        'note',
        'status',
        'user_id',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'table_id' => 'integer',
        'customer_id' => 'integer',
        'paid_date' => 'datetime',
        'total' => 'double',
        'discount' => 'double',
        'tax' => 'double',
        'final_total' => 'double',
        'status' => 'integer',
        'user_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(PaymentDetail::class);
    }
}
