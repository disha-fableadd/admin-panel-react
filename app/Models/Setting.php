<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'gateway_environment',
        'settlement_currency',
        'auto_capture',
        'key_id',
        'key_secret',
        'webhook_secret',
        'status',
    ];
}
