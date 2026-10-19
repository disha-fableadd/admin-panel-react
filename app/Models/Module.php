<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'permission',
        'status',
    ];

    protected $casts = [
        'permission' => 'array',
    ];

    /**
     * The relationships that should always be loaded.
     */
    protected $with = ['subModules'];

    /**
     * Get all sub modules for this module.
     */
    public function subModules()
    {
        return $this->hasMany(SubModule::class, 'module_id');
    }
}
