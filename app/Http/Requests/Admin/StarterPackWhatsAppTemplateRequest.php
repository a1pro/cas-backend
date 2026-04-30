<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class StarterPackWhatsAppTemplateRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('overwrite_existing');
    }

    public function rules(): array
    {
        return [
            'overwrite_existing' => ['nullable', 'boolean'],
        ];
    }

}
