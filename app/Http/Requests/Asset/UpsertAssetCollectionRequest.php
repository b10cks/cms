<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Asset;
use App\Models\Space\AssetCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAssetCollectionRequest extends FormRequest
{
    use ExternalIdValidation;

    public function rules(): array
    {
        $isUpdate = $this->route('collection') !== null;

        return [
            'external_id' => $this->externalIdRule(AssetCollection::class, $this->route('collection')?->id),
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => 'sometimes|nullable|string|max:255',
            'icon' => 'sometimes|nullable|string|max:50',
            'color' => 'sometimes|nullable|string|max:7',
            'type' => ['sometimes', Rule::in([AssetCollection::TYPE_MANUAL, AssetCollection::TYPE_SMART])],
            'rules' => 'sometimes|nullable|array',
            'settings' => 'sometimes|nullable|array',
            'cover_asset_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists(new Asset()->getConnectionName().'.assets', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
