<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'venue_id' => ['nullable', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:120'],
            'journey_type' => ['required', Rule::in(['going_out', 'order_food'])],
            'provider' => ['required', Rule::in(['uber', 'ubereats', 'manual'])],
            'code' => ['required', 'string', 'max:120', 'unique:coupons,code'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'is_new_customer_only' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['required', Rule::in(['draft', 'live', 'expired', 'archived'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
