<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'venue_id',
        'code',
        'destination_postcode',
        'promo_message',
        'voucher_value',
        'service_fee',
        'total_charge',
        'status',
        'issued_at',
        'redeemed_at',
        'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'voucher_value' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_charge' => 'decimal:2',
            'issued_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
