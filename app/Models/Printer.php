<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'printer_type',
        'connection_type',
        'ip_address',
        'port',
        'device_path',
        'is_active',
        'last_status_check',
        'status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_status_check' => 'datetime',
    ];
}
