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
        'client_id',
        'status',
        'description',
    ];

    protected $casts = [
        'client_id' => 'array',
    ];

    protected $appends = ['clients'];

    public function getClientsAttribute()
    {
        if (!empty($this->client_id) && is_array($this->client_id)) {
            return \App\Models\Client::whereIn('id', $this->client_id)->get();
        }
        return [];
    }

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
