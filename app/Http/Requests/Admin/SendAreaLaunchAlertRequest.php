<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class SendAreaLaunchAlertRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

}
