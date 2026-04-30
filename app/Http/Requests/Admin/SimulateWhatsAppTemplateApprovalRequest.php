<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class SimulateWhatsAppTemplateApprovalRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'approval_status' => ['required', 'in:approved,rejected'],
            'approval_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

}
