<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class ListOfferSyncRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:all,pending,synced,rejected,superseded'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

}
