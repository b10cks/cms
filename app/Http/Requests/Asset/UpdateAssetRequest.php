<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'filename' => 'sometimes|string|max:100',
            'external_id' => $this->externalIdRule(Asset::class, $asset?->id),
            'folder_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists(new AssetFolder()->getConnectionName() . '.asset_folders', 'id')
                    ->whereNull('deleted_at'),
            ],
            'metadata' => 'sometimes|nullable|array',
            'data' => 'sometimes|nullable|array',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDecodedJsonInput('metadata');
        $this->mergeDecodedJsonInput('data');
        $this->mergeDecodedJsonInput('tags');
    }

    private function mergeDecodedJsonInput(string $key): void
    {
        if (!$this->has($key)) {
            return;
        }

        $value = $this->input($key);

        if (!\is_string($value)) {
            return;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $this->merge([$key => $decoded]);
    }
}
