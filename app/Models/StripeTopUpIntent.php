<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeTopUpIntent extends Model
{
    protected $fillable = [
        'merchant_id',
        'merchant_wallet_id',
        'amount',
        'currency',
        'mode',
        'provider',
        'checkout_code',
        'status',
        'stripe_payment_intent_id',
        'simulated_payment_reference',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantWallet::class, 'merchant_wallet_id');
    }
}
