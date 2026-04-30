<?php

namespace App\Http\Requests\PublicFlow;

use App\Http\Requests\BaseApiRequest;

class CreateBnplCheckoutRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'plan_key' => ['required', 'string', 'max:50'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'source_context' => ['nullable', 'string', 'max:100'],
        ];
    }
}
