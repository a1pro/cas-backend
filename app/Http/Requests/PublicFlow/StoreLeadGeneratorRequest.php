<?php

namespace App\Http\Requests\PublicFlow;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Validator;

class StoreLeadGeneratorRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput();
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'postcode' => ['required', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:120'],
            'journey_type' => ['required', 'in:nightlife,food'],
            'flow_type' => ['nullable', 'in:going_out,order_food'],
            'desired_venue_name' => ['nullable', 'string', 'max:255'],
            'desired_category' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'in:discovery_no_results,waiting_list,manual,tag_missing_venue'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'submitted_from' => ['nullable', 'string', 'max:120'],
            'contact_consent' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('customer_email')) && blank($this->input('customer_phone'))) {
                $validator->errors()->add('contact', 'Email or phone is required.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'contact_consent.accepted' => 'Contact consent is required.',
        ];
    }
}
