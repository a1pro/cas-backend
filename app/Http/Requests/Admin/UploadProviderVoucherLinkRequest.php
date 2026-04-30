<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class UploadProviderVoucherLinkRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanInput('is_active');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:4096'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'venue_id' => ['nullable', 'exists:venues,id'],
            'venue_code' => ['nullable', 'alpha_num', 'size:6'],
            'provider' => ['nullable', 'in:uber,ubereats'],
            'offer_type' => ['nullable', 'in:food,ride,dual_choice'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'voucher_amount' => ['nullable', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'location_postcode' => ['nullable', 'string', 'max:16'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
            'circulation_mode' => ['nullable', 'in:shared_sequence,unique_individual'],
            'max_issue_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

}
