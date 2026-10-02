<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'status',
    ];

    /**
     * Get the product that owns the project.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
