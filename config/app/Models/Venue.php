<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = [
        'merchant_id',
        'name',
        'category',
        'city',
        'postcode',
        'address',
        'latitude',
        'longitude',
        'description',
        'is_active',
        'offer_enabled',
        'offer_value',
        'offer_days',
        'offer_start_time',
        'offer_end_time',
        'minimum_order',
        'fulfilment_type',
        'offer_review_status',
        'offer_type',
        'ride_trip_type',
        'promo_message',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'offer_enabled' => 'boolean',
            'offer_value' => 'decimal:2',
            'offer_days' => 'array',
            'minimum_order' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }
}
