<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'venue_id',
        'provider_voucher_link_id',
        'code',
        'journey_type',
        'provider_name',
        'offer_type',
        'ride_trip_type',
        'destination_postcode',
        'promo_message',
        'voucher_value',
        'service_fee',
        'total_charge',
        'minimum_order',
        'status',
        'issued_at',
        'redeemed_at',
        'expires_at',
        'external_reference',
        'voucher_link_url',
    ];

    protected function casts(): array
    {
        return [
            'voucher_value' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_charge' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'issued_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function providerVoucherLink()
    {
        return $this->belongsTo(ProviderVoucherLink::class);
    }

    public function providerEvents()
    {
        return $this->hasMany(VoucherProviderEvent::class);
    }
}
