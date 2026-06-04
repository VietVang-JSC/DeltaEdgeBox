<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_level_id',
        'name',
        'storename',
        'code',
        'address',
        'province',
        'phone',
        'email',
        'referer_phone',
        'note',
        'currency',
        'expiry_date',
        'industry_id',
        'company_id',
        'parent_id',
        'is_headquarters',
        'line_user_id',
        'api_key',
        'is_tax_included',
        'printer_host',
        'time_zone',
        'use_node_print_driver',
        'type_check_qr',
        'setting_print_kitchen',
        'current_ip',
        'service_charge',
        'deployment_mode',
        'edge_routing_active',
        'edge_box_url',
        'edge_box_store_id',
        'edge_box_api_key',
        'edge_enabled_at',
        'edge_config_version',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_headquarters' => 'boolean',
        'is_tax_included' => 'boolean',
        'use_node_print_driver' => 'boolean',
        'setting_print_kitchen' => 'array',
        'edge_routing_active' => 'boolean',
        'edge_enabled_at' => 'datetime',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function tables()
    {
        return $this->hasMany(Table::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
