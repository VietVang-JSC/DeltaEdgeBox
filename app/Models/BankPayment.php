<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankPayment extends Model
{
    protected $table = 'bank_payment';

    protected $fillable = [
        'store_id',
        'bank_code',
        'account_number',
        'account_owner',
        'admin_id',
    ];
}
