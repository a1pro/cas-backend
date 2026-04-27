<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

class MerchantRegistrationService
{
    public function register(array $payload): Merchant
    {
        return DB::transaction(function () use ($payload) {
            $user = User::create([
                'name' => $payload['owner_name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'phone' => $payload['contact_phone'],
                'role' => 'merchant',
                'is_active' => false,
            ]);

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $payload['business_name'],
                'business_type' => $payload['business_type'],
                'contact_email' => $payload['email'],
                'contact_phone' => $payload['contact_phone'],
                'whatsapp_number' => $payload['whatsapp_number'] ?? $payload['contact_phone'],
                'status' => 'pending',
                'default_service_fee' => 2.50,
            ]);

            MerchantWallet::create([
                'merchant_id' => $merchant->id,
                'balance' => 0,
                'currency' => 'GBP',
                'low_balance_threshold' => $payload['low_balance_threshold'],
                'auto_top_up_enabled' => false,
                'auto_top_up_amount' => 100,
            ]);

            Venue::create([
                'merchant_id' => $merchant->id,
                'name' => $payload['business_name'],
                'category' => $payload['business_type'],
                'city' => $payload['city'],
                'postcode' => strtoupper($payload['postcode']),
                'address' => $payload['address'],
                'description' => $payload['venue_description'] ?? null,
                'is_active' => false,
                'approval_status' => 'pending',
                'submitted_for_approval_at' => now(),
                'offer_enabled' => false,
                'offer_value' => $payload['offer_value'] ?? 5,
                'offer_days' => ['Fri', 'Sat'],
                'offer_start_time' => '18:00',
                'offer_end_time' => '23:59',
                'minimum_order' => $this->isFoodBusiness($payload['business_type']) ? 25 : null,
                'fulfilment_type' => $this->isFoodBusiness($payload['business_type']) ? 'delivery' : 'venue',
                'promo_message' => 'Unlock your TALK to CAS offer today.',
            ]);

            return $merchant->load(['user', 'wallet', 'venues']);
        });
    }


    private function isFoodBusiness(string $businessType): bool
    {
        return in_array($businessType, ['restaurant', 'takeaway', 'cafe'], true);
    }
}
