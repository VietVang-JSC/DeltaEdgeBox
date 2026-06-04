<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    use SoftDeletes;

    protected $table = 'agencies';

    protected $fillable = [
        'store_id',
        'code',
        'name',
        'contact_person',
        'contact_email',
        'contact_number',
        'company_name',
        'company_tax',
        'address',
        'note',
        'user_init',
        'user_upd',
        'admin_id'
    ];
}
