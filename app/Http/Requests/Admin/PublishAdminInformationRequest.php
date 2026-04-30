<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class PublishAdminInformationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $venue = $this->route('venue');

        return [
            'venue_code' => [
                'required',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
                Rule::unique('venues', 'venue_code')->ignore($venue?->id),
            ],
        ];
    }

}
