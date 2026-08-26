<?php

namespace App\Http\Requests\Team;

use App\Models\Management\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
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
            ],
            'settings' => 'nullable|array'
        ];
    }

    /**
     * The type classifies a team commercially, so only root may set it. Left to
     * `after()` rather than a rule closure: `nullable` short-circuits rules on a
     * null value, and "no type" is the case that has to stay open to everyone.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->user()?->is_root || $this->input('type') === null) {
                    return;
                }

                $validator->errors()->add('type', 'Only a root user may set the team type.');
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
}
