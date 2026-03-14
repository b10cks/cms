<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetFolderRequest extends FormRequest
{
    use ExternalIdValidation;

    public function rules(): array
    {
        return [
            'external_id' => ['sometimes', ...$this->externalIdRule(AssetFolder::class)],
            'name' => 'sometimes|string|max:100',
            'description' => 'sometimes|nullable|string',
            'icon' => 'sometimes|nullable|string|max:50',
            'color' => 'sometimes|nullable|string|max:7',
            'settings' => 'sometimes|nullable|array',
            'settings.field_overrides' => 'nullable|array',
            'settings.field_overrides.*.key' => 'required|string|max:100',
            'settings.field_overrides.*.enabled' => 'nullable|boolean',
            'settings.field_overrides.*.required' => 'nullable|boolean',
            'settings.additional_fields' => 'nullable|array',
            'settings.additional_fields.*.key' => 'required|string|max:100',
            'settings.additional_fields.*.label' => 'required|string|max:100',
            'settings.additional_fields.*.required' => 'required|boolean',
            'parent_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists(new AssetFolder()->getConnectionName() . '.asset_folders', 'id')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $additionalFieldKeys = array_filter(array_map(
                    fn(array $field): ?string => $field['key'] ?? null,
                    $this->input('settings.additional_fields', [])
                ));

                if (count($additionalFieldKeys) !== count(array_unique($additionalFieldKeys))) {
                    $validator->errors()->add('settings.additional_fields', 'Additional field keys must be unique within a folder.');
                }
            },
        ];
    }
}
