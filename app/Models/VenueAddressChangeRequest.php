<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VenueAddressChangeRequest extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'requested_by_user_id',
        'status',
        'current_snapshot',
        'requested_snapshot',
        'request_code',
        'support_message',
        'admin_notes',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'current_snapshot' => 'array',
            'requested_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
