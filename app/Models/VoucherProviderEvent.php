<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherProviderEvent extends Model
{
    protected $fillable = [
        'voucher_id',
        'merchant_id',
        'user_id',
        'provider_name',
        'event_type',
        'verification_result',
        'provider_reference',
        'destination_match',
        'charge_applied',
        'amount_charged',
        'notes',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'destination_match' => 'boolean',
            'charge_applied' => 'boolean',
            'amount_charged' => 'decimal:2',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
