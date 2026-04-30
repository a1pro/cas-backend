<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class VenueExportProviderVoucherLinkRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('missing_only');
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'provider' => ['nullable', 'in:uber,ubereats'],
            'missing_only' => ['nullable', 'boolean'],
        ];
    }

}
