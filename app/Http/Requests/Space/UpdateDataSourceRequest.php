<?php

namespace App\Http\Requests\Space;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDataSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $dataSource = $this->route('dataSource');

        return [
            'name' => 'sometimes|required|string|max:100',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
//                Rule::unique(new DataSource()->getConnectionName() . '.data_sources', 'slug')
//                    ->ignore($dataSource),
            ],
            'description' => 'nullable|string',
            'dimensions' => 'sometimes|required|array',
            'dimensions.*.key' => 'required|string',
            'dimensions.*.label' => 'required|string',
            'settings' => 'nullable|array',
            'settings.dimensions_translatable' => 'nullable|integer|min:0',
            'settings.default_dimension_locale' => 'nullable|string|max:5',
            'settings.cache_ttl' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'dimensions.required' => 'At least one dimension is required.',
            'dimensions.*.required' => 'Dimension labels cannot be empty.',
        ];
    }
}
