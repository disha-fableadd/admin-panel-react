<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TermsAndCondition extends Model
{
    use HasFactory;

    protected $table = 'terms_and_conditions';

    protected $fillable = [
        'product_id',
        'project_id',
        'title',
        'status',
    ];

    protected $casts = [
        'title' => 'array',
        'product_id' => 'integer',
        'project_id' => 'integer',
    ];

    /**
     * Get the product associated with these terms.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the project associated with these terms.
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
