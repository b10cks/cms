<?php

namespace App\Http\Requests\SpaceBlueprint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpaceBlueprintRequest extends FormRequest
{
    public const TABLES = [
        'blocks',
        'block_folders',
        'block_tags',
        'asset_folders',
        'asset_tags',
        'data_sources',
        'block_templates',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'source_space_id' => [
                'nullable',
                'string',
                'max:26',
                Rule::exists('spaces', 'id')->whereNull('deleted_at'),
            ],
            'tables' => 'nullable|array',
            'tables.*' => ['distinct', Rule::in(self::TABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('validation.blueprint.name_required'),
            'color.regex' => __('validation.blueprint.color_invalid'),
            'source_space_id.exists' => __('validation.blueprint.source_space_invalid'),
            'tables.*.in' => __('validation.blueprint.tables_invalid'),
        ];
    }
}
