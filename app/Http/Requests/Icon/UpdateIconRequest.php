<?php

namespace App\Http\Requests\Icon;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Icon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIconRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $icon = $this->route('icon');
        $iconId = $icon instanceof Icon ? $icon->id : $icon;

        return [
            'file' => [
                'sometimes',
                'nullable',
                'file',
                'max:' . (config('filesystems.max_upload_size', 500) * 1024),
            ],
            'body' => ['sometimes', 'nullable', 'string'],
            'external_id' => $this->externalIdRule(Icon::class, $iconId),
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(new Icon()->getConnectionName() . '.icons', 'key')
                    ->whereNull('deleted_at')
                    ->ignore($iconId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'width' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key may only contain lowercase letters, numbers and hyphens.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('tags')) {
            return;
        }

        $value = $this->input('tags');

        if (!\is_string($value)) {
            return;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->merge(['tags' => $decoded]);
        }
    }
}
