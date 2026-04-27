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
        'body',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
