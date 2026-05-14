<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    use HasFactory;

    protected $table = 'print_queue'; // Custom table name

    protected $fillable = [
        'store_id',
        'printer_id',
        'job_type',
        'content',
        'status',
        'priority',
        'retry_count',
        'max_retries',
        'last_error',
        'next_retry_at',
        'completed_at',
    ];

    protected $casts = [
        'next_retry_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
