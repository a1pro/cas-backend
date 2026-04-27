<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveAreaPostcode extends Model
{
    protected $fillable = [
        'postcode_prefix',
        'label',
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
