<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommissionEvent extends Model
{
    protected $fillable = [
        'affiliate_user_id',
        'affiliate_referral_id',
        'referred_user_id',
        'voucher_id',
        'payout_run_id',
        'referral_code',
        'event_type',
        'status',
        'commission_amount',
        'earned_at',
        'paid_at',
        'notified_at',
        'notification_channels',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'commission_amount' => 'decimal:2',
            'earned_at' => 'datetime',
            'paid_at' => 'datetime',
            'notified_at' => 'datetime',
            'notification_channels' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function affiliateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_user_id');
    }

    public function affiliateReferral(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferral::class, 'affiliate_referral_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function payoutRun(): BelongsTo
    {
        return $this->belongsTo(StripePayoutRun::class, 'payout_run_id');
    }
}
