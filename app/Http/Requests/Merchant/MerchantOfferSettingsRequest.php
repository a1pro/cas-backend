<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BaseApiRequest;

class MerchantOfferSettingsRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'offer_enabled' => ['required', 'boolean'],
            'business_type' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
            'offer_type' => ['required', 'in:food,ride,dual_choice'],
            'voucher_amount' => ['required', 'numeric', 'min:1', 'max:100'],
            'offer_days' => ['required', 'array', 'min:1'],
            'offer_days.*' => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'fulfilment_type' => ['nullable', 'in:venue,collection,delivery,both'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'low_balance_threshold' => ['required', 'numeric', 'min:1', 'max:100000'],
            'auto_top_up_enabled' => ['nullable', 'boolean'],
            'auto_top_up_amount' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'urgency_enabled' => ['nullable', 'boolean'],
            'daily_voucher_cap' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
