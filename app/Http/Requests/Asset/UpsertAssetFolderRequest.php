<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

class UpsertAssetFolderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7',
            'parent_id' => [
                'nullable',
                'string',
//                Rule::exists(new AssetFolder()->getConnectionName() . '.asset_folders', 'id')
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
