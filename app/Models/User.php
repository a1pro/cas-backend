<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->exists();
    }

    public function primaryRole(): ?string
    {
        return $this->roles()->value('role');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isMerchant(): bool
    {
        return $this->hasRole('merchant');
    }

    public function isUser(): bool
    {
        return $this->hasRole('user');
    }
}
