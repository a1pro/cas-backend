<?php

namespace App\Services\Merchant;

use App\Models\Merchant;

class MerchantOfferService
{
    public function updateSettings(Merchant $merchant, array $payload): Merchant
    {
        $venue = $merchant->venues()->firstOrFail();
        $wallet = $merchant->wallet()->firstOrFail();

        $venue->update([
            'offer_enabled' => $payload['offer_enabled'],
            'category' => $payload['category'],
            'offer_value' => $payload['offer_value'],
            'offer_days' => $payload['offer_days'],
            'offer_start_time' => $payload['offer_start_time'],
            'offer_end_time' => $payload['offer_end_time'],
            'minimum_order' => $payload['category'] === 'restaurant' ? ($payload['minimum_order'] ?? 25) : null,
            'fulfilment_type' => $payload['category'] === 'restaurant' ? $payload['fulfilment_type'] : 'venue',
            'promo_message' => $payload['promo_message'] ?? null,
        ]);

        $wallet->update([
            'low_balance_threshold' => $payload['low_balance_threshold'],
        ]);

        return $merchant->fresh(['wallet', 'venues']);
    }
}
