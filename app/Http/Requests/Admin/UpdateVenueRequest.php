<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateVenueRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('is_active');
        $this->normalizeBooleanInput('offer_enabled');
        $this->normalizeBooleanInput('urgency_enabled');
    }

    public function rules(): array
    {
        $venue = $this->route('venue');

        return [
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'venue_code' => [
                'nullable',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
                Rule::unique('venues', 'venue_code')->ignore($venue?->id),
            ],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'offer_enabled' => ['nullable', 'boolean'],
            'offer_value' => ['nullable', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'fulfilment_type' => ['nullable', 'string', 'max:80'],
            'urgency_enabled' => ['nullable', 'boolean'],
            'daily_voucher_cap' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'offer_type' => ['nullable', 'in:food,ride,dual_choice'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'promo_message' => ['nullable', 'string', 'max:2000'],
        ];
    }

}
