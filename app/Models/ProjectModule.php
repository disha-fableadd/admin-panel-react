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
        'project_id' => 'array',
    ];

    protected $appends = ['clients', 'projects'];

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

    public function getProjectsAttribute()
    {
        if (!empty($this->project_id) && is_array($this->project_id)) {
            return \App\Models\Project::whereIn('id', $this->project_id)->get();
        }
        return [];
    }

  
}
