<?php

namespace App\Http\Requests\BlockTag;

use App\Models\Space\BlockTag;
use App\Http\Requests\Traits\ExternalIdValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBlockTagRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tag = $this->route('tag');
        $ignoreName = $tag ? $tag->name : null;

        return [
            'external_id' => $this->externalIdRule(BlockTag::class, $ignoreName),
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-_]+$/',
                Rule::unique(new BlockTag()->getConnectionName() . '.block_tags', 'name')
                    ->ignore($ignoreName),
            ],
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'The tag name may only contain letters, numbers, spaces, hyphens, and underscores.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
