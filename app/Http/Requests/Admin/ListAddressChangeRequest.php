<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ListAddressChangeRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,approved,rejected,superseded'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

}
