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
            'is_active' => 'sometimes|boolean',
            ...$dataSource->hasShape()
                ? [
                    ...$this->shapedValueRules($dataSource->shape),
                    'dimensions' => 'nullable|array',
                    ...$this->shapedDimensionRules($dataSource->shape),
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
     * when a non-null value is part of the request, so value can still be
     * cleared with an explicit null.
     *
     * @return array<string, mixed>
     */
    protected function shapedValueRules(array $shape): array
    {
        $value = $this->input('value');
        $rules = ShapeValue::rulesFor($value, $shape, 'value', enforceRequired: $value !== null);
        array_unshift($rules['value'], 'sometimes');

        return $rules;
    }

    /**
     * Per-key dimension rules for shaped sources; legacy string overrides
     * stay valid.
     *
     * @return array<string, mixed>
     */
    protected function shapedDimensionRules(array $shape): array
    {
        $rules = [];

        foreach ((array) $this->input('dimensions', []) as $key => $value) {
            $rules += ShapeValue::rulesFor($value, $shape, "dimensions.{$key}", enforceRequired: false);
        }

        return $rules;
    }
}
