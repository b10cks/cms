<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetFolderRequest extends FormRequest
{
    use ExternalIdValidation;

    public function rules(): array
    {
        return [
            'external_id' => $this->externalIdRule(AssetFolder::class),
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7',
            'settings' => 'nullable|array',
            ...\App\Models\Space\AssetFolderSettings::toValidator('settings'),
            'parent_id' => [
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
