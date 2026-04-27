<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StripePayoutRun extends Model
{
    protected $fillable = [
        'user_id',
        'affiliate_payout_profile_id',
        'provider',
        'payout_code',
        'status',
        'currency',
        'total_amount',
        'commission_event_count',
        'stripe_payout_id',
        'paid_reference',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'commission_event_count' => 'integer',
            'paid_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payoutProfile(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayoutProfile::class, 'affiliate_payout_profile_id');
    }

    public function commissionEvents(): HasMany
    {
        return $this->hasMany(AffiliateCommissionEvent::class, 'payout_run_id');
    }
}
