<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ListProviderVoucherLinkRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('active_only');
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'provider' => ['nullable', 'in:uber,ubereats'],
            'active_only' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

}
