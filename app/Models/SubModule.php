<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'name',
        'permission',
        'status',
    ];

    protected $casts = [
        'permission' => 'array',
    ];

    /**
     * Get the parent module that owns the sub module.
     */
    public function module()
    {
        return $this->belongsTo(Module::class, 'module_id');
    }
}
