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

    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }
}
