<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseApiRequest;

class CreateUserVoucherRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'exists:venues,id'],
            'promo_message' => ['nullable', 'string', 'max:80'],
            'basket_total' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
