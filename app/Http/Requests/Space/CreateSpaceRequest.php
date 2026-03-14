<?php

namespace App\Http\Requests\Space;

use Illuminate\Foundation\Http\FormRequest;

class CreateSpaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization will be handled by policies/middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:50|regex:/^[a-z0-9\-]+$/|unique:spaces,slug,NULL,id,team_id,' . $this->input('team_id'),
            'icon' => 'nullable|string|max:50',
            'team_id' => 'nullable|string|max:26',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'badge' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'settings.asset_fields' => 'nullable|array',
            'settings.asset_fields.*.key' => 'required|string|max:100',
            'settings.asset_fields.*.label' => 'required|string|max:100',
            'settings.asset_fields.*.required' => 'required|boolean',
            'plan_id' => 'nullable|string|exists:plans,id',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $fieldKeys = array_filter(array_map(
                    fn(array $field): ?string => $field['key'] ?? null,
                    $this->input('settings.asset_fields', [])
                ));

                if (count($fieldKeys) !== count(array_unique($fieldKeys))) {
                    $validator->errors()->add('settings.asset_fields', 'Asset field keys must be unique.');
                }
            },
        ];
    }
}
