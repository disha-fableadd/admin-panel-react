<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Renewal extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'renewal_status',
        'amount',
        'previous_end_date',
        'renewal_date',
        'new_end_date',
        'notes',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'renewal_date'      => 'date',
        'new_end_date'      => 'date',
        'amount'            => 'float',
    ];

    /**
     * Client who owns this renewal.
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * How many days left until new_end_date (or days overdue if negative).
     */
    public function getDaysLeftAttribute(): ?int
    {
        if (!$this->new_end_date) return null;
        return (int) Carbon::now()->startOfDay()->diffInDays($this->new_end_date, false);
    }
}
