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
        'billing_id',
        'project_modules_id',
        'plan_name',
        'status',
        'amount',
        'renewal_charge',
        'max_user',
        'max_branch',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'renewal_charge' => 'decimal:2',
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

    /**
     * Get the billing associated with the membership.
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Get the project module associated with the membership.
     */
    public function projectModule()
    {
        return $this->belongsTo(ProjectModule::class, 'project_modules_id');
    }
}
