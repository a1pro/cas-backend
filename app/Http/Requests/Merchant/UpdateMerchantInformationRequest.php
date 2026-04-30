<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BaseApiRequest;

class UpdateMerchantInformationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['sometimes', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
            'promo_message' => ['nullable', 'string', 'max:500'],
            'offer_type' => ['nullable', 'in:ride,food,dual_choice,dual'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'offer_value' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'minimum_order' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }
}
