<?php

namespace App\Http\Requests\Icon;

use App\Http\Requests\Traits\ExternalIdValidation;
use App\Models\Space\Icon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreIconRequest extends FormRequest
{
    use ExternalIdValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // SVG mime detection is inconsistent across platforms, so we only cap the size here
            // and let IconSvgParser be the authoritative content gate (it rejects non-<svg> input).
            'file' => [
                'nullable',
                'file',
                'max:' . (config('filesystems.max_upload_size', 500) * 1024),
            ],
            'body' => ['nullable', 'string'],
            'external_id' => $this->externalIdRule(Icon::class),
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(new Icon()->getConnectionName() . '.icons', 'key')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'width' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->hasFile('file') && blank($this->input('body'))) {
                $validator->errors()->add('file', 'An SVG file or body is required.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key may only contain lowercase letters, numbers and hyphens.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDecodedJsonInput('tags');

        $original = $this->file('file')?->getClientOriginalName();

        if (blank($this->input('key')) && $original) {
            $this->merge(['key' => Str::slug(pathinfo($original, PATHINFO_FILENAME))]);
        }

        if (blank($this->input('name')) && $original) {
            $this->merge(['name' => Str::headline(pathinfo($original, PATHINFO_FILENAME))]);
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
