<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ListVenuesRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('active_only');
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'category' => ['nullable', 'string', 'max:80'],
            'active_only' => ['nullable', 'boolean'],
        ];
    }

}
