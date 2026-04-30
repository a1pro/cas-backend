<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BaseApiRequest;

class CreateStripeTopUpCheckoutRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'mode' => ['nullable', 'in:manual,auto_top_up'],
        ];
    }
}
