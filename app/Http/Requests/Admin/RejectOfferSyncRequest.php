<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class RejectOfferSyncRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

}
