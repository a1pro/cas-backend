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
        'role',
        'phone',
        'postcode',
        'latitude',
        'longitude',
        'is_uber_existing_customer',
        'is_ubereats_existing_customer',
        'provider_profile_updated_at',
        'referred_by_user_id',
        'referral_code_used',
        'referral_attributed_until',
        'fraud_score',
        'fraud_status',
        'fraud_blocked_until',
        'last_device_fingerprint',
        'last_fraud_review_at',
        'is_active',
        'last_login_at',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_uber_existing_customer' => 'boolean',
            'is_ubereats_existing_customer' => 'boolean',
            'provider_profile_updated_at' => 'datetime',
            'referral_attributed_until' => 'datetime',
            'fraud_score' => 'integer',
            'fraud_blocked_until' => 'datetime',
            'last_fraud_review_at' => 'datetime',
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

    public function affiliateProfile()
    {
        return $this->hasOne(AffiliateProfile::class);
    }

    public function affiliateReferrals()
    {
        return $this->hasMany(AffiliateReferral::class, 'affiliate_user_id');
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function fraudSignals()
    {
        return $this->hasMany(FraudSignal::class);
    }

    public function bnplVoucherOrders()
    {
        return $this->hasMany(BnplVoucherOrder::class);
    }

    public function affiliateCommissionEvents()
    {
        return $this->hasMany(AffiliateCommissionEvent::class, 'affiliate_user_id');
    }

    public function affiliatePayoutProfile()
    {
        return $this->hasOne(AffiliatePayoutProfile::class);
    }

    public function stripePayoutRuns()
    {
        return $this->hasMany(StripePayoutRun::class);
    }

    public function createdInformationRecords()
    {
        return $this->hasMany(InformationRecord::class, 'created_by_user_id');
    }

    public function approvedInformationRecords()
    {
        return $this->hasMany(InformationRecord::class, 'approved_by_user_id');
    }

    public function hasRole(string $role): bool
    {
        if ($this->role === $role) {
            return true;
        }

        return $this->roles()->where('role', $role)->exists();
    }

    public function primaryRole(): ?string
    {
        return $this->role ?: $this->roles()->value('role');
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
