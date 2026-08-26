<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'store_id',
        'name',
        'printer_type',
        'connection_type',
        'ip_address',
        'port',
        'device_path',
        'active',
        'default',
        'paper_size',
        'is_active',
        'last_status_check',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'default' => 'boolean',
        'is_active' => 'boolean',
        'last_status_check' => 'datetime',
    ];
}
