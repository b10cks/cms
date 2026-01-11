<?php

namespace App\Http\Requests\Asset;

use App\Models\Space\AssetTag;
use App\Http\Requests\Traits\ExternalIdValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAssetTagRequest extends FormRequest
{
    use ExternalIdValidation;

    public function rules(): array
    {
        return [
            'external_id' => $this->externalIdRule(AssetTag::class),
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique(new AssetTag()->getConnectionName() . '.asset_tags', 'name')
                    ->ignore($this->route('tag'))
            ],
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
