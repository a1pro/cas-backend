<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AreaLaunchAlertRun extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'triggered_by_user_id',
        'trigger_source',
        'postcode_prefix',
        'city',
        'audience_breakdown',
        'attempted_count',
        'sent_count',
        'failed_count',
        'status',
        'notes',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_breakdown' => 'array',
            'sent_at' => 'datetime',
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

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
