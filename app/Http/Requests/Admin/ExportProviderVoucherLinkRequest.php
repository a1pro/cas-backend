<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ExportProviderVoucherLinkRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('active_only');
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'provider' => ['nullable', 'in:uber,ubereats'],
            'active_only' => ['nullable', 'boolean'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'import_batch_code' => ['nullable', 'string', 'max:40'],
        ];
    }

}
