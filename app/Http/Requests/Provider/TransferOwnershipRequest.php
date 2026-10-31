<?php

namespace App\Http\Requests\Provider;

class TransferOwnershipRequest extends ProviderUserActionRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'string', 'exists:teams,id'],
            'new_owner_id' => ['required', 'string', 'exists:users,id'],
        ];
    }
}
