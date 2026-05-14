<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncQueue extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'table_name',
        'operation',
        'record_id',
        'payload',
        'status',
        'priority',
        'retry_count',
        'max_retries',
        'last_error',
        'next_retry_at',
        'synced_at',
        'response_code',
    ];

    protected $casts = [
        'next_retry_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
