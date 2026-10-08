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
        'project_id',
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

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }

    public function setup()
    {
        return $this->hasOne(Setup::class);
    }

    /**
     * Get all renewal records for this client.
     */
    public function renewals()
    {
        return $this->hasMany(Renewal::class);
    }

    protected $appends = ['membership_status', 'calculated_start_date', 'calculated_end_date', 'new_renewal_start_date', 'new_renewal_end_date', 'plan_status', 'days_left', 'days_left_text'];

    public function getCalculatedStartDateAttribute()
    {
        if (!empty($this->start_date)) {
            return \Carbon\Carbon::parse($this->start_date)->format('Y-m-d');
        }
        return $this->created_at ? $this->created_at->format('Y-m-d') : null;
    }

    public function getCalculatedEndDateAttribute()
    {
        if (!empty($this->expiry_date)) {
            return \Carbon\Carbon::parse($this->expiry_date)->format('Y-m-d');
        }

        $startDate = $this->calculated_start_date;
        if (!$startDate) return null;

        $start = \Carbon\Carbon::parse($startDate);
        
        $billingCycle = null;
        if ($this->relationLoaded('setup') && $this->setup) {
            $billingCycle = strtolower($this->setup->billing_cycle);
        } elseif (!$this->relationLoaded('setup')) {
            $setup = clone $this->setup()->first();
            if ($setup) {
                $billingCycle = strtolower($setup->billing_cycle);
            }
        }

        if ($billingCycle === 'monthly' || $billingCycle === 'month') {
            return $start->addMonth()->format('Y-m-d');
        } elseif ($billingCycle === 'yearly' || $billingCycle === 'year') {
            return $start->addYear()->format('Y-m-d');
        }

        return null;
    }

    public function getDaysLeftAttribute(): ?int
    {
        $endDate = $this->calculated_end_date;
        if (!$endDate) return null;

        $target = \Carbon\Carbon::parse($endDate)->startOfDay();
        $today  = \Carbon\Carbon::today();

        return (int) abs($today->diffInDays($target, false));
    }

    public function getDaysLeftTextAttribute(): string
    {
        $endDate = $this->calculated_end_date;
        if (!$endDate) return 'N/A';

        $target = \Carbon\Carbon::parse($endDate)->startOfDay();
        $today  = \Carbon\Carbon::today();
        $diff = (int) $today->diffInDays($target, false);

        if ($diff > 1) {
            return "{$diff} Days Left";
        } elseif ($diff === 1) {
            return "1 Day Left";
        } elseif ($diff === 0) {
            return "Today";
        } else {
            return "Expired (" . abs($diff) . "d ago)";
        }
    }

    public function getMembershipStatusAttribute()
    {
        $endDate = $this->calculated_end_date;
        if (!$endDate) return null;

        $end = \Carbon\Carbon::parse($endDate)->endOfDay();
        $now = \Carbon\Carbon::now();

        if ($now->isAfter($end)) {
            return 'Expired';
        }

        if ($now->copy()->addWeek()->isAfter($end) || $now->copy()->addWeek()->isSameDay($end)) {
            return 'Expiring Soon';
        }

        return 'Ongoing';
    }

    /**
     * Convert the model instance to an array.
     * Synchronizes membership project modules with client-level setup assigned_modules override.
     */
    public function toArray()
    {
        $array = parent::toArray();

        $assignedModules = null;
        if (isset($array['setup']['assigned_modules']) && is_array($array['setup']['assigned_modules'])) {
            $assignedModules = $array['setup']['assigned_modules'];
        } elseif ($this->relationLoaded('setup') && $this->setup && is_array($this->setup->assigned_modules)) {
            $assignedModules = $this->setup->assigned_modules;
        }

        if ($assignedModules !== null && !empty($array['membership'])) {
            $assignedLookup = [];
            foreach ($assignedModules as $m) {
                if (is_numeric($m)) {
                    $assignedLookup['id_' . (int)$m] = true;
                } else {
                    $cleanName = strtolower(trim(preg_replace('/\s*\(Project:.*?\)$/i', '', (string)$m)));
                    $assignedLookup['name_' . $cleanName] = true;
                }
            }

            if (!empty($array['membership']['project_modules_data']) && is_array($array['membership']['project_modules_data'])) {
                $filteredData = array_values(array_filter($array['membership']['project_modules_data'], function($mod) use ($assignedLookup) {
                    $modId = isset($mod['id']) ? (int)$mod['id'] : null;
                    $modName = isset($mod['name']) ? strtolower(trim($mod['name'])) : '';
                    return (isset($assignedLookup['id_' . $modId]) || isset($assignedLookup['name_' . $modName]));
                }));
                $array['membership']['project_modules_data'] = $filteredData;
                $array['membership']['project_modules_id'] = array_values(array_column($filteredData, 'id'));
            }
        }

        return $array;
    }
    public function getNewRenewalStartDateAttribute()
    {
        $status = $this->membership_status;
        if (!in_array($status, ['Expired', 'Expiring Soon'])) {
            return null;
        }

        if (empty($this->expiry_date)) {
            return \Carbon\Carbon::today()->format('Y-m-d');
        }
        
        $expiryDate = \Carbon\Carbon::parse($this->expiry_date);
        $today = \Carbon\Carbon::today();
        
        if ($expiryDate->isPast() && !$expiryDate->isToday()) {
            return $today->format('Y-m-d');
        }
        
        return $expiryDate->format('Y-m-d');
    }

    public function getNewRenewalEndDateAttribute()
    {
        $startDateStr = $this->new_renewal_start_date;
        if (!$startDateStr) return null;

        $start = \Carbon\Carbon::parse($startDateStr);
        
        $billingCycle = null;
        if ($this->relationLoaded('setup') && $this->setup) {
            $billingCycle = strtolower($this->setup->billing_cycle);
        } elseif (!$this->relationLoaded('setup')) {
            $setup = clone $this->setup()->first();
            if ($setup) {
                $billingCycle = strtolower($setup->billing_cycle);
            }
        }

        if ($billingCycle === 'monthly' || $billingCycle === 'month') {
            return $start->addMonth()->format('Y-m-d');
        }
        return $start->addYear()->format('Y-m-d');
    }

    public function getPlanStatusAttribute()
    {
        // A client is on a renewal if they have any completed renewal records
        if ($this->relationLoaded('renewals')) {
             $hasRenewals = $this->renewals->where('renewal_status', 'Completed')->count() > 0;
        } else {
             $hasRenewals = $this->renewals()->where('renewal_status', 'Completed')->exists();
        }
        return $hasRenewals ? 'Renewed' : 'Purchase';
    }
}
