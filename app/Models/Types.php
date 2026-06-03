<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Types extends Model
{
    use HasFactory;
    protected $table = 'types';

    protected $fillable = [
        'product_type_name',
        'admin_id',
        'store_id'
    ];
    
    public $timestamps = true;

    public function productTypes(){
        return $this->hasMany(ProductType::class, 'product_type_id', 'id');
    }
}
