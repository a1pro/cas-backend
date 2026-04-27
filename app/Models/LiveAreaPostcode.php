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

    protected $appends = [
        'postcode',
        'priority',
    ];

    public function getPostcodeAttribute(): ?string
    {
        return $this->postcode_prefix;
    }

    public function getPriorityAttribute(): int
    {
        return (int) ($this->sort_order ?? 0);
    }
}
