<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Kolom "password" Laravel diganti "pin".
     * Dipakai Laravel kalau suatu saat ada yang memanggil Auth::attempt().
     */
    protected $authPasswordName = 'pin';

    /**
     * Sengaja TANPA pin, phone_verified_at, dan kolom lockout:
     * semuanya hanya boleh di-set lewat service (forceFill), bukan mass assignment.
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
    ];

    protected $hidden = [
        'pin',
        'remember_token',
        'pin_failed_attempts',
        'pin_locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'pin_failed_attempts' => 'integer',
            'pin' => 'hashed',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }
}