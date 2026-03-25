<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'business_type',
        'contact_email',
        'contact_phone',
        'whatsapp_number',
        'default_service_fee',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'default_service_fee' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->hasOne(MerchantWallet::class);
    }

    public function venues()
    {
        return $this->hasMany(Venue::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
