<?php

namespace App\Http\Requests\Space;

use App\Models\Space\DataEntry;
use App\Http\Requests\Traits\ExternalIdValidation;
use App\Services\Space\ShapeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDataEntryRequest extends FormRequest
{
    use ExternalIdValidation;

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
        $dataSource = $this->route('data_source');
        $dataEntry = $this->route('entry');

        return [
            'external_id' => $this->externalIdRule(DataEntry::class, $dataEntry->id),
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9._\-]+$/',
                Rule::unique(new DataEntry()->getConnectionName() . '.data_entries', 'key')
                    ->where('data_source_id', $dataSource->id)
                    ->ignore($dataEntry->id),
            ],
            ...$dataSource->hasShape()
                ? [
                    ...$this->shapedValueRules($dataSource->shape),
                    'dimensions' => 'nullable|array',
                    ...ShapeValue::rules($dataSource->shape, 'dimensions.*', enforceRequired: false),
                ]
                : [
                    'value' => 'sometimes|nullable|string',
                    'dimensions' => 'nullable|array',
                    'dimensions.*' => 'nullable|string',
                ],
        ];
    }

    /**
     * Value rules for shaped sources; required fields are only enforced
     * when the value is part of the request.
     *
     * @return array<string, mixed>
     */
    protected function shapedValueRules(array $shape): array
    {
        $rules = ShapeValue::rules($shape, 'value', enforceRequired: $this->has('value'));
        array_unshift($rules['value'], 'sometimes');

        return $rules;
    }
}
