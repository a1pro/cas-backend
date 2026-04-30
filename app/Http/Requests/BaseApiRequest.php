<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseApiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    abstract public function rules(): array;

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Normalize boolean input values from strings to actual booleans.
     */
    protected function normalizeBooleanInput(): void
    {
        $input = $this->all();
        
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = strtolower(trim($value));
                if (in_array($value, ['true', '1', 'yes', 'on'])) {
                    $this->merge([$key => true]);
                } elseif (in_array($value, ['false', '0', 'no', 'off'])) {
                    $this->merge([$key => false]);
                }
            }
        }
    }
}
