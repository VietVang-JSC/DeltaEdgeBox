<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_code',
        'parent_id',
        'store_id',
        'table_id',
        'customer_id',
        'reason',
        'items',
        'paid_date',
        'total',
        'discount',
        'surcharge',
        'surcharge_reason',
        'surcharge_percent',
        'service_charge',
        'service_charge_amount',
        'tax',
        'final_total',
        'payment_method',
        'note',
        'status',
        'is_printed',
        'invoice_sent_status',
        'user_id',
        'admin_id',
        'financial_id',
        'number_of_people',
        'type_discount',
        'discount_percent',
        'payment_transaction_id',
        'sub_total_before_discount',
        'total_incl_vat_before_discount',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'store_id' => 'integer',
        'table_id' => 'integer',
        'customer_id' => 'integer',
        'paid_date' => 'datetime',
        'total' => 'double',
        'discount' => 'double',
        'surcharge' => 'double',
        'surcharge_percent' => 'integer',
        'service_charge' => 'integer',
        'service_charge_amount' => 'decimal:4',
        'tax' => 'double',
        'final_total' => 'double',
        'status' => 'integer',
        'is_printed' => 'boolean',
        'invoice_sent_status' => 'boolean',
        'user_id' => 'integer',
        'admin_id' => 'integer',
        'financial_id' => 'integer',
        'number_of_people' => 'integer',
        'discount_percent' => 'integer',
        'sub_total_before_discount' => 'decimal:4',
        'total_incl_vat_before_discount' => 'decimal:4',
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

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'value');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function details()
    {
        return $this->hasMany(PaymentDetail::class);
    }
}
