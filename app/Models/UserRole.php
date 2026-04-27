<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (UserRole $userRole) {
            $userRole->user()?->update(['role' => $userRole->role]);
        });

        static::deleted(function (UserRole $userRole) {
            $user = $userRole->user;

            if (! $user) {
                return;
            }

            $nextRole = static::query()
                ->where('user_id', $user->id)
                ->orderByDesc('assigned_at')
                ->value('role');

            $user->update(['role' => $nextRole ?: 'user']);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
