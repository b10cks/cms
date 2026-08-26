<?php

namespace App\Http\Requests\Team;

use App\Models\Management\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $team = $this->route('team');

        return [
            'name' => 'sometimes|required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => [
                'nullable',
                'string',
                'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'
            ],
            'description' => 'nullable|string',
            'type' => ['nullable', 'string', Rule::in(Team::TYPES)],
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
                function ($attribute, $value, $fail) use ($team) {
                    // Prevent setting self as parent
                    if ($value === $team->id) {
                        $fail('A team cannot be its own parent.');
                    }

                    // Prevent circular dependencies
                    if ($value && $this->wouldCreateCircularDependency($team, $value)) {
                        $fail('This would create a circular dependency in the team hierarchy.');
                    }
                }
            ],
            'settings' => 'nullable|array'
        ];
    }

    /**
     * The type classifies a team commercially, so only root may change it.
     * Left to `after()` rather than a rule closure: `nullable` short-circuits
     * rules on a null value, and clearing the type has to be caught too.
     * Re-sending the value the team already carries is a no-op, so it passes.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->user()?->is_root || ! $this->has('type')) {
                    return;
                }

                if ($this->input('type') !== $this->route('team')?->type) {
                    $validator->errors()->add('type', 'Only a root user may change the team type.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The team name is required.',
            'name.max' => 'The team name may not be greater than 100 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
            'type.in' => 'The type must be one of: '.implode(', ', Team::TYPES).'.',
            'parent_id.exists' => 'The selected parent team does not exist.',
        ];
    }

    private function wouldCreateCircularDependency($team, $newParentId): bool
    {
        $currentTeam = Team::find($newParentId);

        while ($currentTeam) {
            if ($currentTeam->id === $team->id) {
                return true;
            }
            $currentTeam = $currentTeam->parent;
        }

        return false;
    }
}
