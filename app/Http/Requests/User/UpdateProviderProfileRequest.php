<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseApiRequest;

class UpdateProviderProfileRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'is_uber_existing_customer' => ['nullable', 'boolean'],
            'is_ubereats_existing_customer' => ['nullable', 'boolean'],
        ];
    }
}
