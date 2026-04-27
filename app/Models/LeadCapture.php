<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadCapture extends Model
{
    protected $fillable = [
        'user_id',
        'matched_merchant_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'postcode',
        'city',
        'journey_type',
        'desired_venue_name',
        'desired_category',
        'source',
        'status',
        'notes',
        'contact_consent',
        'notified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'contact_consent' => 'boolean',
            'metadata' => 'array',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function matchedMerchant()
    {
        return $this->belongsTo(Merchant::class, 'matched_merchant_id');
    }
}
