<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class UploadCouponsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'merchant_id' => ['required', 'exists:merchants,id'],
            'venue_id' => ['nullable', 'exists:venues,id'],
        ];
    }

}
