<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncMetadata extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'last_sync_timestamp',
        'sync_status',
        'pending_records_count',
        'last_error',
    ];

    protected $casts = [
        'last_sync_timestamp' => 'datetime',
    ];
}
