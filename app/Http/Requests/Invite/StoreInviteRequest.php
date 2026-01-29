<?php

namespace App\Http\Requests\Invite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleRules = [
            'required',
            'string',
        ];
        if ($this->input('team_id')) {
            $roleRules[] = Rule::in(['member', 'admin', 'owner', 'guest', 'space']);
        }
        // TODO: When space roles are implemented, validate the role against the space's allowed roles

        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('invites', 'email')->where(function ($query) {
                    if ($spaceId = $this->input('space_id')) {
                        return $query->where('space_id', $spaceId);
                    }
                    if ($teamId = $this->input('team_id')) {
                        return $query->where('team_id', $teamId);
                    }
                }),
            ],
            'role' => [
                'required',
                'string',
            ],
            'space_id' => [
                'nullable',
                'string',
                'exists:spaces,id'
            ],
            'team_id' => [
                'nullable',
                'string',
                'exists:teams,id'
            ],
            'message' => 'nullable|string|max:1000',
            'expires_in_days' => 'nullable|integer|min:1|max:90',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('expires_in_days')) {
            $this->merge(['expires_in_days' => 7]);
        }
    }

    public function messages(): array
    {
        return [
            'email.required' => 'The email address is required.',
            'email.email' => 'The email address must be valid.',
            'role.required' => 'The role is required.',
            'role.in' => 'The role must be one of: owner, admin, editor, member, viewer.',
            'space_id.exists' => 'The selected space does not exist.',
            'team_id.exists' => 'The selected team does not exist.',
            'message.max' => 'The message may not be greater than 1000 characters.',
            'expires_in_days.min' => 'The invitation must expire in at least 1 day.',
            'expires_in_days.max' => 'The invitation may not expire in more than 90 days.',
        ];
    }

    public function getExpiresAt(): \Illuminate\Support\Carbon
    {
        return now()->addDays($this->input('expires_in_days', 7));
    }
}
