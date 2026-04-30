<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class MerchantEligibilityRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
            'requested_plan' => ['nullable', 'in:free_trial,payg'],
        ];
    }
}
