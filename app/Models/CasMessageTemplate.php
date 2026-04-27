<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CasMessageTemplate extends Model
{
    protected $fillable = [
        'key',
        'channel',
        'journey_type',
        'weather_condition',
        'emoji',
        'category',
        'language',
        'provider_template_id',
        'provider_template_name',
        'approval_status',
        'approval_notes',
        'last_submitted_at',
        'last_synced_at',
        'body',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_submitted_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
