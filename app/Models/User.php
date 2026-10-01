<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'position',
        'email',
        'phone_number',
        'password',
        'role_id',
        'status',
        'avatar',
    ];

    /**
     * Get the role associated with the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the permissions for the user.
     */
    public function permissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the user's avatar with full URL.
     *
     * @param  string  $value
     * @return string|null
     */
    public function getAvatarAttribute($value)
    {
        if ($value) {
            // Check if it's already a full URL (e.g. from a third-party auth)
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                return $value;
            }
            return url($value);
        }
        
        return null;
    }
}
