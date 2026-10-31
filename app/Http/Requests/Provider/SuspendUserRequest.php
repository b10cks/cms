<?php

namespace App\Http\Requests\Provider;

class SuspendUserRequest extends ProviderUserActionRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'replacement_owners' => ['sometimes', 'array'],
            'replacement_owners.*' => ['string', 'exists:users,id'],
        ];
    }

    /** @return array<string, string> team id => new owner user id */
    public function replacementOwners(): array
    {
        return array_filter(
            (array) $this->validated('replacement_owners', []),
            fn ($ownerId, $teamId) => is_string($teamId) && is_string($ownerId),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
