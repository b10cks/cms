<?php

namespace App\Http\Requests\Block;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\BlockSettings;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBlockRequest extends FormRequest
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
        return [
            'external_id' => $this->externalIdRule(Block::class),
            'name' => 'required|string|max:100',
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9A-Z]+$/',
                Rule::unique(new Block()->getConnectionName() . '.blocks', 'slug')
                    ->whereNull('deleted_at')
            ],
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'description' => 'nullable|string',
            'type' => 'required|string|in:root,nestable,single,universal',
            'preview_template' => 'nullable|string',
            'preview_file' => 'nullable|string|max:255',
            'schema' => 'nullable|array',
            'editor' => 'nullable|array',
            'settings' => 'nullable|array',
            ...BlockSettings::toValidator('settings', true),
            'tags' => 'nullable|array',
            'folder_id' => [
                'nullable',
                'string',
                Rule::exists(new BlockFolder()->getConnectionName() . '.block_folders', 'id')
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $schema = $this->input('schema');
        $editor = $this->input('editor');

        if (is_array($schema)) {
            $this->merge(['schema' => \App\Services\Content\Schema\BlockSchema::fromArray($schema)->toArray()]);
        }

        if (is_array($editor)) {
            $this->merge(['editor' => array_values($editor)]);
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must start with a lowercase letter and contain only letters and numbers.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $errors = app(BlockSchemaRequestValidator::class)->validate(
                    $this->input('schema', []),
                    $this->input('editor', []),
                );

                foreach ($errors as $path => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($path, $message);
                    }
                }
            },
        ];
    }
}
