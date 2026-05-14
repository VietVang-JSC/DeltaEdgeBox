<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'image',
        'sort_order',
        'status',
        'parent_id',
        'admin_id',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'sort_order' => 'integer',
        'status' => 'boolean',
        'parent_id' => 'integer',
        'admin_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
