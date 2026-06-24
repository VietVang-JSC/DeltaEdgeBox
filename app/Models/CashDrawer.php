<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDrawer extends Model
{
    protected $table = 'cash_drawers';

    protected $fillable = [
        'store_id',
        'start_user_id',
        'end_user_id',
        'start_amount',
        'end_amount',
        'owner_withdraw_amount',
        'currency_code',
        'status',
        'note',
        'started_at',
        'ended_at',
    ];
}
