<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VenueTag extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'session_id',
        'invited_merchant_id',
        'venue_name',
        'normalized_name',
        'share_code',
        'inviter_name',
        'inviter_phone',
        'inviter_email',
        'source_channel',
        'status',
        'expires_at',
        'joined_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'joined_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WhatsAppSession::class, 'session_id');
    }

    public function invitedMerchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'invited_merchant_id');
    }

    public function rewardCredit(): HasOne
    {
        return $this->hasOne(TagRewardCredit::class);
    }
}
