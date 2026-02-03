<?php

namespace App\Http\Requests\BlockTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlockTemplateRequest extends FormRequest
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
            'color' => 'sometimes|nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'description' => 'sometimes|nullable|string',
            'content' => 'sometimes|required|array',
            'preview_file' => 'sometimes|nullable|string|max:255',
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
