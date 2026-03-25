<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantWallet extends Model
{
    protected $fillable = [
        'merchant_id',
        'balance',
        'currency',
        'low_balance_threshold',
        'auto_top_up_enabled',
        'auto_top_up_amount',
        'last_alert_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'low_balance_threshold' => 'decimal:2',
            'auto_top_up_enabled' => 'boolean',
            'auto_top_up_amount' => 'decimal:2',
            'last_alert_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
