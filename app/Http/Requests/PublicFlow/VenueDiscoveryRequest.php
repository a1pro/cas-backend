<?php

namespace App\Http\Requests\PublicFlow;

use App\Http\Requests\BaseApiRequest;

class VenueDiscoveryRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'flow_type' => ['nullable', 'in:going_out,order_food'],
            'postcode' => ['nullable', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'basket_total' => ['nullable', 'numeric', 'min:0'],
            'is_uber_existing_customer' => ['nullable', 'boolean'],
            'is_ubereats_existing_customer' => ['nullable', 'boolean'],
        ];
    }
}
