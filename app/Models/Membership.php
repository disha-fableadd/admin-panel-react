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

    protected $appends = ['project_modules_data'];

    protected $casts = [
        'is_custom_billing' => 'boolean',
        'amount' => 'array',
        'renewal_amount' => 'array',
        'project_modules_id' => 'array',
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
     * Get the project modules details using the JSON array of IDs.
     */
    public function getProjectModulesDataAttribute()
    {
        $ids = $this->project_modules_id ?? [];
        if (empty($ids)) return [];
        return ProjectModule::whereIn('id', $ids)->get();
    }

    /**
     * Get all clients subscribed to this membership plan.
     */
    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
