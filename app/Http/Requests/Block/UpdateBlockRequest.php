<?php

namespace App\Http\Requests\Block;

use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Http\Requests\Traits\ExternalIdValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBlockRequest extends FormRequest
{
    use ExternalIdValidation;

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
        $block = $this->route('block');

        return [
            'external_id' => $this->externalIdRule(Block::class, $block->id),
            'name' => 'sometimes|required|string|max:100',
            'icon' => 'sometimes|nullable|string|max:50',
            'color' => 'sometimes|nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'slug' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9A-Z]+$/',
                Rule::unique(new Block()->getConnectionName() . '.blocks', 'slug')
                    ->whereNull('deleted_at')
                    ->ignore($block->id),
            ],

            'description' => 'sometimes|nullable|string',
            'type' => 'sometimes|string|in:root,nestable,single,universal',
            'preview_template' => 'sometimes|nullable|string',
            'schema' => 'sometimes|nullable|array',
            'editor' => 'sometimes|nullable|array',
            'tags' => 'sometimes|nullable|array',
            'folder_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists(new BlockFolder()->getConnectionName() . '.block_folders', 'id')
                    ->whereNull('deleted_at')
            ],
            'commit_message' => 'nullable|string|max:500',
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
