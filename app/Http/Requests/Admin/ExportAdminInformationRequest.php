<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ExportAdminInformationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'in:csv,xls,excel'],
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
        ];
    }

}
