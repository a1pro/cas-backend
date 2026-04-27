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
        'approval_status',
        'venue_code',
        'submitted_for_approval_at',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejection_reason',
        'offer_enabled',
        'offer_value',
        'offer_days',
        'offer_start_time',
        'offer_end_time',
        'minimum_order',
        'fulfilment_type',
        'offer_review_status',
        'urgency_enabled',
        'daily_voucher_cap',
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
            'urgency_enabled' => 'boolean',
            'daily_voucher_cap' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'submitted_for_approval_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function providerVoucherLinks()
    {
        return $this->hasMany(ProviderVoucherLink::class);
    }

    public function addressChangeRequests()
    {
        return $this->hasMany(VenueAddressChangeRequest::class);
    }

    public function informationRecords()
    {
        return $this->hasMany(InformationRecord::class);
    }
}
