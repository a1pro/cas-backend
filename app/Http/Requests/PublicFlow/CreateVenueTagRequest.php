<?php

namespace App\Http\Requests\PublicFlow;

use App\Http\Requests\BaseApiRequest;

class CreateVenueTagRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:255'],
            'inviter_name' => ['nullable', 'string', 'max:255'],
            'inviter_phone' => ['nullable', 'string', 'max:50'],
            'inviter_email' => ['nullable', 'email', 'max:255'],
            'venue_contact_email' => ['nullable', 'email', 'max:255'],
            'venue_contact_phone' => ['nullable', 'string', 'max:50'],
            'source_channel' => ['nullable', 'string', 'max:50'],
        ];
    }
}
