<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'product_id',
        'project_id',
        'status',
        'description',
    ];

    /**
     * Get the product that owns the module.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the project that owns the module.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
