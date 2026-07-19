<?php

namespace App\Http\Requests\Invite;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Auth\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleService = app(RoleService::class);
        $team = $this->route('team');
        $space = $this->route('space');

        $allowedRoleKeys = [];
        if ($team instanceof Team) {
            $allowedRoleKeys = array_map(fn ($role) => $role->key, $roleService->teamCatalog()->all());
        } elseif ($space instanceof Space) {
            $allowedRoleKeys = array_map(
                fn ($role) => $role->key,
                $roleService->spaceCatalogForTeam($space->team)->all(),
            );
        }

        $roleRules = [
            'required',
            'string',
            Rule::in($allowedRoleKeys),
        ];

        return [
            'email' => [
                'required',
                'email',
                'max:255',
                // Only a still-pending invite blocks a new one; expired or
                // declined invites are superseded and accepted ones are
                // history (the member may have been removed since).
                Rule::unique('invites', 'email')
                    ->where(function ($query) {
                        $query->whereNull('accepted_at')
                            ->whereNull('declined_at')
                            ->where('expires_at', '>', now());

                        if ($space = $this->route('space')) {
                            return $query->where('space_id', $space->id);
                        }
                        if ($team = $this->route('team')) {
                            return $query->where('team_id', $team->id);
                        }
                    }),
            ],
            'role' => $roleRules,
            'message' => 'nullable|string|max:1000',
            'expires_in_days' => 'nullable|integer|min:1|max:90',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('expires_in_days')) {
            $this->merge(['expires_in_days' => 7]);
        }

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function messages(): array
    {
        return [
            'email.required' => 'The email address is required.',
            'email.email' => 'The email address must be valid.',
            'email.unique' => 'A pending invitation already exists for this email address.',
            'role.required' => 'The role is required.',
            'role.in' => 'The selected role is invalid for this invite.',
            'message.max' => 'The message may not be greater than 1000 characters.',
            'expires_in_days.min' => 'The invitation must expire in at least 1 day.',
            'expires_in_days.max' => 'The invitation may not expire in more than 90 days.',
        ];
    }

    public function getExpiresAt(): Carbon
    {
        return now()->addDays($this->input('expires_in_days', 7));
    }
}
