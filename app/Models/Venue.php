<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = [
        'merchant_id',
        'name',
        'category',
        'city',
        'postcode',
        'address',
        'latitude',
        'longitude',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }
}
