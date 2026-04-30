<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class MerchantRegisterRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
            'tag_code' => ['nullable', 'string', 'max:24', 'exists:venue_tags,share_code'],
            'requested_plan' => ['nullable', 'in:free_trial,payg'],
        ];
    }
}
