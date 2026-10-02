<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setup extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'domain',
        'db_credential',
    ];

    protected $casts = [
        'db_credential' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
