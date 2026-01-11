<?php

namespace App\Services\Documentation;

class TypeInferencer
{
    /**
     * Infer OpenAPI type from Laravel validation rules
     */
    public function inferFromValidationRules(array $rules): array
    {
        $schema = [];

        // Extract base type from rules
        if (in_array('string', $rules)) {
            $schema['type'] = 'string';
        } elseif (in_array('integer', $rules) || in_array('int', $rules)) {
            $schema['type'] = 'integer';
        } elseif (in_array('numeric', $rules)) {
            $schema['type'] = 'number';
        } elseif (in_array('boolean', $rules)) {
            $schema['type'] = 'boolean';
        } elseif (in_array('array', $rules)) {
            $schema['type'] = 'array';
        } elseif (in_array('file', $rules)) {
            $schema['type'] = 'string';
            $schema['format'] = 'binary';
        } elseif (in_array('image', $rules)) {
            $schema['type'] = 'string';
            $schema['format'] = 'binary';
        } else {
            $schema['type'] = 'string'; // Default
        }

        // Add constraints from rules
        foreach ($rules as $rule) {
            // Handle max constraint
            if (str_starts_with($rule, 'max:')) {
                $value = (int) substr($rule, 4);
                if ($schema['type'] === 'string') {
                    $schema['maxLength'] = $value;
                } elseif ($schema['type'] === 'integer') {
                    $schema['maximum'] = $value;
                } else {
                    $schema['max'] = $value;
                }
            }

            // Handle min constraint
            if (str_starts_with($rule, 'min:')) {
                $value = (int) substr($rule, 4);
                if ($schema['type'] === 'string') {
                    $schema['minLength'] = $value;
                } elseif ($schema['type'] === 'integer') {
                    $schema['minimum'] = $value;
                } else {
                    $schema['min'] = $value;
                }
            }

            // Handle regex pattern
            if (str_starts_with($rule, 'regex:')) {
                $pattern = substr($rule, 6);
                // Remove leading/trailing slashes if present
                if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                    $pattern = substr($pattern, 1, -1);
                }
                $schema['pattern'] = $pattern;
            }

            // Handle enum values
            if (str_starts_with($rule, 'in:')) {
                $values = explode(',', substr($rule, 3));
                $schema['enum'] = array_map('trim', $values);
            }

            // Handle specific formats
            if ($rule === 'email') {
                $schema['format'] = 'email';
            }

            if ($rule === 'url') {
                $schema['format'] = 'uri';
            }

            if ($rule === 'date') {
                $schema['format'] = 'date';
            }

            if ($rule === 'date_format:Y-m-d\TH:i:s\Z' || $rule === 'date_format:c') {
                $schema['format'] = 'date-time';
            }

            if ($rule === 'uuid') {
                $schema['format'] = 'uuid';
            }

            if ($rule === 'json') {
                $schema['type'] = 'object';
            }
        }

        // Handle nullable fields
        if (in_array('nullable', $rules)) {
            return [
                'oneOf' => [
                    $schema,
                    ['type' => 'null'],
                ],
            ];
        }

        return $schema;
    }

    /**
     * Infer OpenAPI type from field name
     */
    public function inferFromFieldName(string $fieldName): array
    {
        // ID fields
        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        // Timestamp fields
        if (in_array($fieldName, ['created_at', 'updated_at', 'published_at', 'deleted_at', 'expires_at'])) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // Count fields
        if (str_ends_with($fieldName, '_count')) {
            return ['type' => 'integer'];
        }

        // Email fields
        if (str_contains($fieldName, 'email')) {
            return ['type' => 'string', 'format' => 'email'];
        }

        // URL/Link fields
        if (str_contains($fieldName, 'url') || str_contains($fieldName, 'link') || str_contains($fieldName, 'uri')) {
            return ['type' => 'string', 'format' => 'uri'];
        }

        // Boolean fields
        if (str_starts_with($fieldName, 'is_') || str_starts_with($fieldName, 'has_') || str_starts_with($fieldName, 'can_')) {
            return ['type' => 'boolean'];
        }

        // Default to string
        return ['type' => 'string'];
    }

    /**
     * Infer OpenAPI type from Laravel model
     */
    public function inferFromModel(string $modelClass): array
    {
        try {
            $model = new $modelClass();
            $keyType = $model->getKeyType();

            if ($keyType === 'string') {
                return ['type' => 'string', 'format' => 'uuid'];
            }

            return ['type' => 'integer'];
        } catch (\Exception $e) {
            // Fallback to string
            return ['type' => 'string'];
        }
    }

    /**
     * Convert rule object to string representation
     */
    public function ruleToString($rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if (is_object($rule)) {
            // Skip closures and other non-stringifiable objects
            if ($rule instanceof \Closure) {
                return '';
            }

            try {
                return (string) $rule;
            } catch (\Exception $e) {
                return '';
            }
        }

        return '';
    }

    /**
     * Parse validation rule string or array
     */
    public function normalizeRules($rules): array
    {
        if (is_string($rules)) {
            return array_filter(explode('|', $rules));
        }

        if (is_array($rules)) {
            $result = [];
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $result[] = $rule;
                } elseif (is_object($rule) && !($rule instanceof \Closure)) {
                    $ruleStr = $this->ruleToString($rule);
                    if (!empty($ruleStr)) {
                        $result[] = $ruleStr;
                    }
                }
            }
            return array_filter($result);
        }

        return [];
    }
}
