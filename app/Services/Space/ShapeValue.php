<?php

namespace App\Services\Space;

use Illuminate\Validation\Rule;

/**
 * Encodes/decodes data entry values for data sources with a shape and
 * builds the validation rules for structured values.
 *
 * Entries of shapeless sources are plain strings and pass through untouched.
 */
class ShapeValue
{
    /**
     * Build Laravel validation rules for a structured value at $prefix.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $shape, string $prefix, bool $enforceRequired): array
    {
        $rules = [$prefix => ['nullable', 'array']];

        foreach ($shape as $field) {
            $key = $field['key'] ?? null;

            if (!$key) {
                continue;
            }

            $path = "{$prefix}.{$key}";
            $required = $enforceRequired && ($field['required'] ?? false);
            $optionValues = array_column($field['options'] ?? [], 'value');

            $rules[$path] = match ($field['type'] ?? 'text') {
                'number' => ['numeric'],
                'boolean' => ['boolean'],
                'date' => ['date'],
                'option' => [Rule::in($optionValues)],
                'options' => ['array'],
                default => ['string'],
            };

            if (($field['type'] ?? null) === 'options') {
                $rules["{$path}.*"] = [Rule::in($optionValues)];
            }

            array_unshift($rules[$path], $required ? 'required' : 'nullable');
        }

        return $rules;
    }

    /**
     * JSON-encode a structured value for storage, stripping unknown keys.
     * Strings (shapeless values) pass through unchanged.
     */
    public static function encode(mixed $value, array $shape): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        $known = array_flip(array_filter(array_column($shape, 'key')));

        return json_encode(array_intersect_key($value, $known));
    }

    /**
     * Decode a stored value for output. Without a shape the raw value is
     * returned as-is; with a shape it is JSON-decoded, falling back to the
     * raw string for values that predate the shape.
     */
    public static function decode(mixed $raw, ?array $shape): mixed
    {
        if (empty($shape) || !is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $raw;
    }
}
