<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => 'nullable|string|max:50|in:partner,reseller,affiliate',
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

    public function messages(): array
    {
        return [
            'name.required' => 'The team name is required.',
            'name.max' => 'The team name may not be greater than 100 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
            'type.in' => 'The type must be one of: partner, reseller, affiliate.',
            'parent_id.exists' => 'The selected parent team does not exist.',
        ];
    }

    private function wouldCreateCircularDependency($team, $newParentId): bool
    {
        $currentTeam = \App\Models\Management\Team::find($newParentId);

        while ($currentTeam) {
            if ($currentTeam->id === $team->id) {
                return true;
            }
            $currentTeam = $currentTeam->parent;
        }

        return false;
    }
}
