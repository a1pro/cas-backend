<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BaseApiRequest;

class CreateMerchantVoucherRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255'],
            'user_phone' => ['nullable', 'string', 'max:50'],
            'journey_type' => ['required', 'in:ride,food'],
            'voucher_value' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'promo_message' => ['nullable', 'string', 'max:80'],
        ];
    }
}
