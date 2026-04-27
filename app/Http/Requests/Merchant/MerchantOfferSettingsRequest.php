<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class MerchantOfferSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offer_enabled' => ['required', 'boolean'],
            'category' => ['required', 'in:club,bar,restaurant'],
            'offer_value' => ['required', 'numeric', 'min:2', 'max:10'],
            'offer_days' => ['required', 'array', 'min:1'],
            'offer_days.*' => ['string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'offer_start_time' => ['required', 'date_format:H:i'],
            'offer_end_time' => ['required', 'date_format:H:i'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'fulfilment_type' => ['required', 'in:venue,collection,delivery,both'],
            'promo_message' => ['nullable', 'string', 'max:80'],
            'low_balance_threshold' => ['required', 'numeric', 'min:0'],
        ];
    }
}
