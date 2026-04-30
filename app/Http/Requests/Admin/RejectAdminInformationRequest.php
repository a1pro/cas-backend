<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class RejectAdminInformationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

}
