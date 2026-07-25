<?php

namespace App\Http\Requests\Block;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\BlockSettings;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
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
            'preview_file' => 'sometimes|nullable|string|max:255',
            'schema' => 'sometimes|nullable|array',
            'editor' => 'sometimes|nullable|array',
            'settings' => 'sometimes|nullable|array',
            ...BlockSettings::toValidator('settings', true),
            'tags' => 'sometimes|nullable|array',
            'folder_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists(new BlockFolder()->getConnectionName() . '.block_folders', 'id')
            ],
            'commit_message' => 'nullable|string|max:500',
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
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if (! $this->has('schema') && ! $this->has('editor')) {
                    return;
                }

                $schema = $this->input('schema', $this->route('block')?->schema?->toArray() ?? []);
                $editor = $this->input('editor', $this->route('block')?->editor ?? []);
                $errors = app(BlockSchemaRequestValidator::class)->validate($schema, $editor);

                foreach ($errors as $path => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($path, $message);
                    }
                }
            },
        ];
    }
}
