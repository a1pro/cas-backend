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
        'onboarding_plan',
        'free_trial_status',
        'free_trial_ineligible_reason',
        'free_trial_message',
        'trial_blocked_keywords',
        'normalized_trial_address',
    ];

    protected function casts(): array
    {
        return [
            'default_service_fee' => 'decimal:2',
            'trial_blocked_keywords' => 'array',
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

    public function stripeTopUpIntents()
    {
        return $this->hasMany(StripeTopUpIntent::class);
    }

    public function providerVoucherLinks()
    {
        return $this->hasMany(ProviderVoucherLink::class);
    }

    public function addressChangeRequests()
    {
        return $this->hasMany(VenueAddressChangeRequest::class);
    }

    public function informationRecords()
    {
        return $this->hasMany(InformationRecord::class);
    }
}
