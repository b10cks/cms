<?php

namespace App\Http\Requests\BlockTemplate;

use App\Models\Space\Block;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class CreateBlockTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $connectionName = new Block()->getConnectionName();

        return [
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'description' => 'nullable|string',
            'content' => 'required|array',
            'preview_file' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'color.regex' => __('validation.block_template.color_regex'),
            'name.required' => __('validation.block_template.name_required'),
            'content.required' => __('validation.block_template.content_required'),
        ];
    }
}
