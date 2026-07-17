<?php

namespace App\Services\Space;

/**
 * Validates a data source shape definition — an ordered list of simple
 * field definitions describing the structure of the source's entry values.
 */
class DataSourceShapeValidator
{
    public const TYPES = ['text', 'textarea', 'number', 'boolean', 'date', 'option', 'options'];

    public const OPTION_TYPES = ['option', 'options'];

    /**
     * Cross-field checks that go beyond the per-key request rules.
     *
     * @return array<string, array<int, string>> errors keyed by "shape.{index}.{attribute}"
     */
    public function validate(array $shape): array
    {
        $errors = [];

        foreach (array_values($shape) as $index => $field) {
            if (!is_array($field)) {
                $errors["shape.{$index}"][] = 'Each shape field must be an object.';
                continue;
            }

            $type = $field['type'] ?? null;
            $options = $field['options'] ?? null;

            if (in_array($type, self::OPTION_TYPES, true) && empty($options)) {
                $errors["shape.{$index}.options"][] = 'Options are required for option and options fields.';
            }

            if (!in_array($type, self::OPTION_TYPES, true) && !empty($options)) {
                $errors["shape.{$index}.options"][] = 'Options are only allowed for option and options fields.';
            }

            if (array_key_exists('default', $field) && $field['default'] !== null) {
                $error = $this->validateDefault($type, $field['default'], $options ?? []);

                if ($error !== null) {
                    $errors["shape.{$index}.default"][] = $error;
                }
            }
        }

        return $errors;
    }

    protected function validateDefault(?string $type, mixed $default, array $options): ?string
    {
        $optionValues = array_column($options, 'value');

        return match ($type) {
            'text', 'textarea' => is_string($default) ? null : 'The default must be a string.',
            'number' => is_numeric($default) ? null : 'The default must be a number.',
            'boolean' => is_bool($default) ? null : 'The default must be a boolean.',
            'date' => strtotime((string) $default) !== false ? null : 'The default must be a valid date.',
            'option' => in_array($default, $optionValues, true) ? null : 'The default must be one of the option values.',
            'options' => is_array($default) && array_diff($default, $optionValues) === []
                ? null
                : 'The default must be an array of option values.',
            default => null,
        };
    }
}
