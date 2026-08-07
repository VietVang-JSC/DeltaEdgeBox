<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenPaymentMappingOutbox extends Model
{
    protected $table = 'open_payment_mapping_outbox';

    protected $fillable = [
        'store_id',
        'source_table',
        'local_id',
        'cloud_id',
        'status',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'cloud_id' => 'integer',
        'attempts' => 'integer',
    ];
}
