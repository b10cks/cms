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
}
