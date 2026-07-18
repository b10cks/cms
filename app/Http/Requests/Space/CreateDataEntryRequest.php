<?php

namespace App\Http\Requests\Space;

use App\Models\Space\DataEntry;
use App\Http\Requests\Traits\ExternalIdValidation;
use App\Services\Space\ShapeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDataEntryRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $dataSource = $this->route('data_source');

        return [
            'external_id' => $this->externalIdRule(DataEntry::class),
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9._\-]+$/',
                Rule::unique(new DataEntry()->getConnectionName() . '.data_entries', 'key')
                    ->where('data_source_id', $dataSource->id),
            ],
            'is_active' => 'sometimes|boolean',
            ...$dataSource->hasShape()
                ? [
                    ...ShapeValue::rules($dataSource->shape, 'value', enforceRequired: true),
                    'dimensions' => 'nullable|array',
                    ...ShapeValue::rules($dataSource->shape, 'dimensions.*', enforceRequired: false),
                ]
                : [
                    'value' => 'nullable|string',
                    'dimensions' => 'nullable|array',
                    'dimensions.*' => 'nullable|string',
                ],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key may only contain letters, numbers, dots, underscores, and hyphens.',
            'key.unique' => 'This key already exists in the data source.',
        ];
    }
}
