<?php

namespace App\Http\Requests\Space;

use App\Models\Space\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique(new DataSource()->getConnectionName() . '.data_sources', 'slug'),
            ],
            'description' => 'nullable|string',
            'dimensions' => 'nullable|array',
            'dimensions.*.key' => 'required|string',
            'dimensions.*.label' => 'required|string',
            'settings' => 'nullable|array',
            'settings.cache_ttl' => 'nullable|integer|min:0',
            'settings.dimensions_translatable' => 'nullable|boolean',
            'settings.default_dimension_locale' => 'nullable|string|max:5',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'dimensions.required' => 'At least one dimension is required.',
            'dimensions.*.required' => 'Dimension labels cannot be empty.',
        ];
    }
}
