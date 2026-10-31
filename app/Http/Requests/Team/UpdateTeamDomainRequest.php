<?php

namespace App\Http\Requests\Team;

use App\Enums\TeamDomainPolicy;
use App\Models\Management\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy' => ['sometimes', 'required', Rule::enum(TeamDomainPolicy::class)],
            // Auto-join never mints owners.
            'default_role' => ['sometimes', 'required', 'string', Rule::in(self::defaultRoleKeys())],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('policy') !== TeamDomainPolicy::RequireSso->value) {
                    return;
                }

                /** @var Team $team */
                $team = $this->route('team');
                if (! $team->samlProvider()->where('enabled', true)->exists()) {
                    $validator->errors()->add('policy', __('Enable SAML single sign-on for this team first.'));
                }
            },
        ];
    }

    /** @return array<int, string> */
    public static function defaultRoleKeys(): array
    {
        return array_values(array_diff(array_keys(config('authorization.roles.team', [])), ['owner']));
    }
}
