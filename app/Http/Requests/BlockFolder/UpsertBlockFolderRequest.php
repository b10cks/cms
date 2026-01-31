<?php

namespace App\Http\Requests\BlockFolder;

use App\Models\Space\BlockFolder;
use App\Http\Requests\Traits\ExternalIdValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBlockFolderRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $folder = $this->route('folder');
        $folderId = $folder ? $folder->id : null;

        return [
            'external_id' => $this->externalIdRule(BlockFolder::class, $folderId),
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique(new BlockFolder()->getConnectionName() . '.block_folders', 'name')
                    ->ignore($folderId),
            ],
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'description' => 'nullable|string|max:500',
        ];
    }
}
