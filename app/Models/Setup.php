<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setup extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_name',
        'product',
        'domain',
        'database_name',
        'db_host',
        'db_username',
        'db_password',
        'db_port',
        'ssl_enabled',
        'status',
        'version',
        'plan_name',
        'billing_cycle',
        'amount',
        'renewal_amount',
        'assigned_modules',
    ];

    protected $casts = [
        'ssl_enabled' => 'boolean',
        'assigned_modules' => 'array',
        'amount' => 'decimal:2',
        'renewal_amount' => 'decimal:2',
    ];

    protected $appends = ['new_renewal_start_date', 'new_renewal_end_date', 'plan_status'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function getNewRenewalStartDateAttribute()
    {
        return $this->client ? $this->client->new_renewal_start_date : null;
    }

    public function getNewRenewalEndDateAttribute()
    {
        return $this->client ? $this->client->new_renewal_end_date : null;
    }

    public function getPlanStatusAttribute()
    {
        return $this->client ? $this->client->plan_status : 'Purchase';
    }
}
