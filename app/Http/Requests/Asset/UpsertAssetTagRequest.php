<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

class UpsertAssetTagRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
//                Rule::unique(new AssetTag()->getConnectionName() . '.asset_tags', 'name')
//                    ->ignore($this->route('tag'))
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
