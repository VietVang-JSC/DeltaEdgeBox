<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'table_name',
        'operation',
        'record_id',
        'status',
        'error_message',
        'duration_ms',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public $timestamps = false; // Only use created_at
}
