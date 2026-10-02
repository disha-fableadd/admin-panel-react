<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
    ];

    /**
     * Get the project modules associated with the product.
     */
    public function projectModules()
    {
        return $this->hasMany(ProjectModule::class);
    }
}
