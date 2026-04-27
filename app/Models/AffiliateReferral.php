<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends Model
{
    protected $fillable = [
        'affiliate_profile_id',
        'affiliate_user_id',
        'referred_user_id',
        'referral_code',
        'signed_up_at',
        'attributed_until',
        'first_voucher_issued_at',
        'first_voucher_redeemed_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'signed_up_at' => 'datetime',
            'attributed_until' => 'datetime',
            'first_voucher_issued_at' => 'datetime',
            'first_voucher_redeemed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function affiliateProfile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class);
    }

    public function affiliateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
