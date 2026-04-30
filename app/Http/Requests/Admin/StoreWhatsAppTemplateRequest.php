<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class StoreWhatsAppTemplateRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('is_active');
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            'journey_type' => ['nullable', 'string', 'max:50'],
            'weather_condition' => ['nullable', 'string', 'max:50'],
            'emoji' => ['nullable', 'string', 'max:12'],
            'body' => ['required', 'string', 'max:1024'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'category' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:20'],
        ];
    }

}
