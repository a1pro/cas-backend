<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ReviewFraudUserRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

}
