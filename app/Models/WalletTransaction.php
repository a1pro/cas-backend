<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'merchant_id',
        'merchant_wallet_id',
        'voucher_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function wallet()
    {
        return $this->belongsTo(MerchantWallet::class, 'merchant_wallet_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
