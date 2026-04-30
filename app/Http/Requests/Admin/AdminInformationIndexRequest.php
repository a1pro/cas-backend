<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class AdminInformationIndexRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ];
    }

}
