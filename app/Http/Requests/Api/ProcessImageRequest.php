<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProcessImageRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const array TRANSFORMATION_KEYS = ['w', 'h', 'c', 'g', 'x', 'y', 'tw', 'th'];

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json(['error' => 'Invalid transformation parameters', 'details' => $validator->errors()], 422)
        );
    }

    public function rules(): array
    {
        $maxWidth = (int) config('ilum.max_dimensions.width', 5000);
        $maxHeight = (int) config('ilum.max_dimensions.height', 5000);

        return [
            'format' => ['nullable', 'string', Rule::in(array_keys(config('ilum.formats', [])))],
            'quality' => ['nullable', 'integer', 'between:1,100'],
            'c' => ['nullable', 'string', Rule::in(['fill', 'fit', 'crop'])],
            'g' => ['nullable', 'string'],
            'w' => ['nullable', 'integer', 'min:1', 'max:' . $maxWidth],
            'h' => ['nullable', 'integer', 'min:1', 'max:' . $maxHeight],
            'x' => ['nullable', 'integer', 'min:0', 'max:' . $maxWidth],
            'y' => ['nullable', 'integer', 'min:0', 'max:' . $maxHeight],
            'tw' => ['nullable', 'integer', 'min:1', 'max:' . $maxWidth],
            'th' => ['nullable', 'integer', 'min:1', 'max:' . $maxHeight],
        ];
    }

    protected function prepareForValidation(): void
    {
        $params = $this->parseTransformations($this->route('transformations'));

        $this->merge([
            ...$this->clampDimensions($params),
            'format' => $this->query('format'),
            'quality' => $this->query('quality'),
        ]);
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $cropMode = $this->input('c');
                $width = $this->integerOrNull('w');
                $height = $this->integerOrNull('h');
                $targetWidth = $this->integerOrNull('tw');
                $targetHeight = $this->integerOrNull('th');
                $gravity = $this->input('g');

                if ($gravity !== null && ! $this->isValidGravity($gravity)) {
                    $validator->errors()->add('g', 'The selected gravity is invalid.');
                }

                if ($cropMode === 'fill' && ($width === null || $height === null)) {
                    $validator->errors()->add('c', 'Fill transformations require both width and height.');
                }

                if ($cropMode === 'crop' && ($width === null || $height === null)) {
                    $validator->errors()->add('c', 'Crop transformations require both width and height.');
                }

                if ($cropMode === 'crop' && (($targetWidth === null) !== ($targetHeight === null))) {
                    $validator->errors()->add('tw', 'Crop resize transformations require both target width and target height.');
                }

                if ($cropMode === 'fit' && $width === null && $height === null) {
                    $validator->errors()->add('w', 'At least one dimension must be provided for resize transformations.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformationParameters(): array
    {
        $parameters = [];

        foreach (self::TRANSFORMATION_KEYS as $key) {
            if ($this->has($key)) {
                $parameters[$key] = $this->input($key);
            }
        }

        return $parameters;
    }

    private function integerOrNull(string $key): ?int
    {
        return $this->filled($key) ? (int) $this->input($key) : null;
    }

    private function isValidGravity(string $gravity): bool
    {
        if (\in_array($gravity, ['face', 'center', 'auto'], true)) {
            return true;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity, $matches)) {
            return false;
        }

        return $matches[1] >= 0 && $matches[1] <= 100
            && $matches[2] >= 0 && $matches[2] <= 100;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function clampDimensions(array $params): array
    {
        $maxWidth = (int) config('ilum.max_dimensions.width', 5000);
        $maxHeight = (int) config('ilum.max_dimensions.height', 5000);

        foreach (['w', 'tw'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $params[$key] = min((int) $params[$key], $maxWidth);
            }
        }

        foreach (['h', 'th'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $params[$key] = min((int) $params[$key], $maxHeight);
            }
        }

        foreach (['x'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $params[$key] = min(max((int) $params[$key], 0), $maxWidth);
            }
        }

        foreach (['y'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $params[$key] = min(max((int) $params[$key], 0), $maxHeight);
            }
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function parseTransformations(?string $transformations): array
    {
        if (! $transformations) {
            return [];
        }

        $params = [];

        foreach (\explode(',', $transformations) as $part) {
            if ($part === '') {
                continue;
            }

            [$key, $value] = \array_pad(\explode('_', $part, 2), 2, null);

            if ($key && $value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
