<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderVoucherLink extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'venue_code_reference',
        'created_by_user_id',
        'provider',
        'link_url',
        'offer_type',
        'ride_trip_type',
        'voucher_amount',
        'minimum_order',
        'location_postcode',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
        'is_active',
        'circulation_mode',
        'max_issue_count',
        'source',
        'import_batch_code',
        'source_label',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'voucher_amount' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
            'max_issue_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }


    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
