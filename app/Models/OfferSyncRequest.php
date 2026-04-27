<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfferSyncRequest extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'requested_by_user_id',
        'status',
        'previous_snapshot',
        'requested_snapshot',
        'changed_fields',
        'export_code',
        'sync_due_at',
        'synced_at',
        'rejected_at',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'previous_snapshot' => 'array',
            'requested_snapshot' => 'array',
            'changed_fields' => 'array',
            'sync_due_at' => 'datetime',
            'synced_at' => 'datetime',
            'rejected_at' => 'datetime',
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
