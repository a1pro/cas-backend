<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayoutProfile extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'payout_email',
        'country_code',
        'currency',
        'onboarding_status',
        'stripe_account_id',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted_at',
        'approved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
