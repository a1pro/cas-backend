<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ListMerchantsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,approved,rejected,active,inactive'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

}
