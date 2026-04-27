<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'created_by',
        'title',
        'journey_type',
        'provider',
        'code',
        'discount_amount',
        'minimum_order',
        'is_new_customer_only',
        'starts_at',
        'expires_at',
        'status',
        'source',
        'uploaded_file_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'is_new_customer_only' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
