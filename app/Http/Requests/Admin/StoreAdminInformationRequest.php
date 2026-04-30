<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreAdminInformationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
            'promo_message' => ['nullable', 'string', 'max:500'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'venue_code' => $this->venueCodeRules(),
            'offer_type' => ['nullable', 'in:ride,food,dual_choice,dual'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'offer_value' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'minimum_order' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }

    private function venueCodeRules(): array
    {
        return [
            'nullable',
            'string',
            'size:6',
            'regex:/^[A-Za-z0-9]{6}$/',
            Rule::unique('venues', 'venue_code'),
        ];
    }

}
