<?php

namespace App\Http\Requests\SpaceBlueprint;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpaceBlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'icon' => 'sometimes|nullable|string|max:50',
            'color' => ['sometimes', 'nullable', 'string', 'max:7', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'description' => 'sometimes|nullable|string',
            'settings' => 'sometimes|nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('validation.blueprint.name_required'),
            'color.regex' => __('validation.blueprint.color_invalid'),
        ];
    }
}
