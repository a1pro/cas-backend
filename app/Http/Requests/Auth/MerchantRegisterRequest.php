<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class MerchantRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:20'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
            'low_balance_threshold' => ['required', 'numeric', 'min:0'],
            'offer_value' => ['nullable', 'numeric', 'min:2', 'max:10'],
        ];
    }
}
