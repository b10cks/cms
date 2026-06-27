<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => 'nullable|string|max:50|in:partner,reseller,affiliate',
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
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
}
