<?php

namespace App\Services\Ai\Run;

/**
 * Checks a decoded value against the JSON schema subset skills use: `type`,
 * `properties`, `required`, `items`, `enum` and `additionalProperties: false`.
 */
final class OutputSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> errors, empty when valid
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $type = $schema['type'] ?? null;

        if (\is_string($type) && ! self::isType($value, $type)) {
            return ["{$path} must be of type {$type}."];
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && ! \in_array($value, $schema['enum'], true)) {
            return ["{$path} must be one of ".implode(', ', array_map('json_encode', $schema['enum'])).'.'];
        }

        $errors = [];

        if (\is_array($value) && ($type === 'object' || isset($schema['properties']))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! \array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key} is required.";
                }
            }

            foreach ($value as $key => $item) {
                $property = $schema['properties'][$key] ?? null;

                if (\is_array($property)) {
                    array_push($errors, ...self::validate($item, $property, "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key} is not allowed.";
                } elseif (\is_array($schema['additionalProperties'] ?? null)) {
                    array_push($errors, ...self::validate($item, $schema['additionalProperties'], "{$path}.{$key}"));
                }
            }
        }

        if ($type === 'array' && \is_array($value) && \is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                array_push($errors, ...self::validate($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        return \array_slice($errors, 0, 10);
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => \is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => \is_array($value) && array_is_list($value),
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
