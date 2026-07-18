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
     * Rules for a single value that may predate the shape: legacy plain
     * strings stay valid so existing entries remain editable after a shape
     * is added; anything else validates against the shape.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(mixed $value, array $shape, string $path, bool $enforceRequired): array
    {
        if (is_string($value)) {
            return [$path => ['nullable', 'string']];
        }

        return self::rules($shape, $path, $enforceRequired);
    }

    /**
     * Human-readable label for an entry value: the first non-empty scalar
     * field in shape order, or the raw string for shapeless/legacy values.
     */
    public static function label(mixed $raw, ?array $shape, string $fallback): string
    {
        $value = self::decode($raw, $shape);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value)) {
            foreach ($shape ?? [] as $field) {
                $candidate = $value[$field['key'] ?? ''] ?? null;

                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }

                if (is_int($candidate) || is_float($candidate)) {
                    return (string) $candidate;
                }
            }
        }

        return $fallback;
    }

    /**
     * Resolve the delivered value for a dimension: structured overrides are
     * merged per-field over the base value (null/missing fields fall back),
     * mirroring how shapeless dimensions fall back to the base string.
     */
    public static function resolveDimension(mixed $baseRaw, mixed $overrideRaw, ?array $shape): mixed
    {
        $base = self::decode($baseRaw, $shape);
        $override = self::decode($overrideRaw, $shape);

        if ($override === null) {
            return $base;
        }

        if (is_array($base) && is_array($override)) {
            return array_merge($base, array_filter($override, fn ($field) => $field !== null));
        }

        return $override;
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
     * raw string for values that predate the shape. Empty strings represent
     * cleared values and decode to null.
     */
    public static function decode(mixed $raw, ?array $shape): mixed
    {
        if (empty($shape) || !is_string($raw)) {
            return $raw;
        }

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $raw;
    }
}
