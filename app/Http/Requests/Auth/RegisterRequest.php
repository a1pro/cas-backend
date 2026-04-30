<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class RegisterRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['nullable', 'in:user,merchant'],
            'business_name' => ['required_if:role,merchant', 'nullable', 'string', 'max:255'],
            'business_type' => ['required_if:role,merchant', 'nullable', 'in:club,bar,restaurant,takeaway,cafe'],
            'contact_phone' => ['required_if:role,merchant', 'nullable', 'string', 'max:50'],
            'postcode' => ['required_if:role,merchant', 'nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'referral_code' => ['nullable', 'string', 'max:24', 'exists:affiliate_profiles,share_code'],
        ];
    }
}
