<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_name',
        'brand_name',
        'work_email',
        'mobile',
        'product_id',
        'membership_id',
        'status',
        'is_custom_billing',
        'billing_title',
        'amount',
        'renewal_amount',
        'start_date',
        'expiry_date',
        'location',
    ];

    protected $casts = [
        'is_custom_billing' => 'boolean',
        'amount' => 'array',
        'renewal_amount' => 'array',
    ];

    // Many-to-Many relationship with ProjectModule
    public function projectModules()
    {
        return $this->belongsToMany(ProjectModule::class, 'client_project_modules');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }

    public function setup()
    {
        return $this->hasOne(Setup::class);
    }
}
