<?php

namespace App\Http\Requests\Block;

use Illuminate\Foundation\Http\FormRequest;

class CreateBlockRequest extends FormRequest
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
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9A-Z]+$/',
//                Rule::unique(new Block()->getConnectionName() . '.blocks', 'slug')
            ],
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'description' => 'nullable|string',
            'type' => 'required|string|in:root,nestable,single,universal',
            'preview_template' => 'nullable|string',
            'schema' => 'nullable|array',
            'editor' => 'nullable|array',
            'tags' => 'nullable|array',
            'folder_id' => [
                'nullable',
                'string',
//                Rule::exists(new BlockFolder()->getConnectionName() . '.block_folders', 'id')
            ],
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
}
