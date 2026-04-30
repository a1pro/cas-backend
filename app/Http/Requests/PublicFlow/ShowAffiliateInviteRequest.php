<?php

namespace App\Http\Requests\PublicFlow;

use App\Http\Requests\BaseApiRequest;

class ShowAffiliateInviteRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'track' => ['nullable', 'boolean'],
        ];
    }
}
