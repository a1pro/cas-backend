<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BaseApiRequest;

class SimulateProviderEventRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'in:ride_completed,order_completed,order_cancelled,destination_mismatch,ride_terminated_early'],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'destination_match' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
