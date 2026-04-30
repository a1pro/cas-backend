<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class UpdateMerchantRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('user_is_active');
    }

    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'default_service_fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:pending,active,inactive,rejected'],
            'user_name' => ['nullable', 'string', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255'],
            'user_is_active' => ['nullable', 'boolean'],
            'wallet_low_balance_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }

}
