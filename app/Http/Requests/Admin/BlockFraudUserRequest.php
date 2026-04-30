<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class BlockFraudUserRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

}
