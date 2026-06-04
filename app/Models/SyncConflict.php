<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'sync_queue_id',
        'cloud_conflict_id',
        'table_name',
        'record_id',
        'operation_type',
        'local_data',
        'cloud_data',
        'resolution_strategy',
        'resolution_status',
        'error_message',
        'resolved_at',
    ];

    protected $casts = [
        'local_data' => 'array',
        'cloud_data' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function syncQueue()
    {
        return $this->belongsTo(SyncQueue::class);
    }
}
