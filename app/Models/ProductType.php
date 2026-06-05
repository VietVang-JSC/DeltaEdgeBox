<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    use HasFactory;
    protected $table = 'product_type';

    protected $fillable = [
        'product_type_id',
        'product_type_attribute',
        'product_type_attribute_value',
        'admin_id',
        'store_id'
    ];
    
    public $timestamps = true;
}
