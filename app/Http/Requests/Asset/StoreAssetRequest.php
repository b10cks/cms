<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Http\Requests\Traits\RejectsActiveContentUploads;
use App\Models\Space\Asset;
use Illuminate\Support\Arr;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    use ExternalIdValidation;
    use RejectsActiveContentUploads;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . (config('filesystems.max_upload_size', 500) * 1024),
                $this->rejectActiveContentRule(),
            ],
            'external_id' => $this->externalIdRule(Asset::class),
            'folder_id' => [
                'nullable',
                'string',
                Rule::exists(new AssetFolder()->getConnectionName() . '.asset_folders', 'id')
                    ->whereNull('deleted_at'),
            ],
            'metadata' => 'nullable|array',
            'data' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'force' => 'sometimes|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDecodedJsonInput('metadata');
        $this->mergeDecodedJsonInput('data');
        $this->mergeDecodedJsonInput('tags');

        if (is_array($this->input('metadata'))) {
            $this->merge(['metadata' => Arr::except($this->input('metadata'), Asset::SERVER_METADATA_KEYS)]);
        }
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
