<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'project_id',
        'project_modules_id',
        'is_custom_billing',
        'billing_title',
        'plan_name',
        'status',
        'amount',
        'renewal_amount',
        'max_user',
        'max_branch',
        'notes',
    ];

    protected $casts = [
        'is_custom_billing' => 'boolean',
        'amount' => 'array',
        'renewal_amount' => 'array',
        'max_user' => 'integer',
        'max_branch' => 'integer',
    ];

    /**
     * Get the product associated with the membership.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the project associated with the membership.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // billing relation removed as it is handled manually now

    /**
     * Get the project module associated with the membership.
     */
    public function projectModule()
    {
        return $this->belongsTo(ProjectModule::class, 'project_modules_id');
    }
}
