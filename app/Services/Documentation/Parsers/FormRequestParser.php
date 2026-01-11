<?php

namespace App\Services\Documentation\Parsers;

use App\Services\Documentation\TypeInferencer;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;

class FormRequestParser
{
    public function __construct(
        private TypeInferencer $typeInferencer
    ) {}

    /**
     * Parse a FormRequest class to extract request body schema
     */
    public function parse(string $formRequestClass): array
    {
        if (!class_exists($formRequestClass) || !is_subclass_of($formRequestClass, FormRequest::class)) {
            return [];
        }

        try {
            // Create a temporary instance without full request lifecycle
            $reflection = new ReflectionClass($formRequestClass);

            // Try to call rules() method safely
            try {
                $instance = new $formRequestClass();
                $rules = $instance->rules();
            } catch (\Exception $e) {
                // If instantiation fails, try parsing the rules() method source
                $rulesMethod = $reflection->getMethod('rules');
                $rules = $this->extractRulesFromMethod($rulesMethod, $formRequestClass);

                if (empty($rules)) {
                    return [];
                }
            }

            return [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $this->buildSchemaFromRules($rules),
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Extract rules array from FormRequest rules() method by parsing source
     */
    protected function extractRulesFromMethod(\ReflectionMethod $method, string $formRequestClass): array
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!file_exists($filename)) {
                return [];
            }

            $lines = file($filename);
            $methodCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // This is a simplified extraction - just return empty for complex cases
            // In a production environment, you might use PHP parser here too
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build OpenAPI schema from validation rules
     */
    protected function buildSchemaFromRules(array $rules): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        // Separate flat and nested rules
        $flatRules = [];
        $nestedRules = [];

        foreach ($rules as $field => $rule) {
            if (str_contains($field, '.')) {
                $nestedRules[$field] = $rule;
            } else {
                $flatRules[$field] = $rule;
            }
        }

        // Process flat rules first
        foreach ($flatRules as $field => $rule) {
            $ruleArray = $this->typeInferencer->normalizeRules($rule);
            $fieldSchema = $this->typeInferencer->inferFromValidationRules($ruleArray);

            $schema['properties'][$field] = $fieldSchema;

            if (in_array('required', $ruleArray) && !in_array('nullable', $ruleArray)) {
                $schema['required'][] = $field;
            }
        }

        // Process nested rules
        if (!empty($nestedRules)) {
            $schema = $this->processNestedRules($schema, $nestedRules);
        }

        return $schema;
    }

    /**
     * Process nested validation rules (e.g., dimensions.*.key)
     */
    protected function processNestedRules(array $schema, array $nestedRules): array
    {
        // Build tree structure from dot notation
        $tree = [];

        foreach ($nestedRules as $field => $rule) {
            $parts = explode('.', $field);
            $current = &$tree;

            foreach ($parts as $part) {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }

            $current['__rule__'] = $rule;
        }

        // Convert tree to schema properties
        foreach ($tree as $key => $value) {
            $schema['properties'][$key] = $this->buildNestedSchema($value);
        }

        return $schema;
    }

    /**
     * Build nested object/array schema from tree structure
     */
    protected function buildNestedSchema(array $structure): array
    {
        // Handle wildcard (array items)
        if (isset($structure['*'])) {
            return [
                'type' => 'array',
                'items' => $this->buildNestedSchema($structure['*']),
            ];
        }

        // Check if this is a leaf node with rules
        if (isset($structure['__rule__'])) {
            $ruleArray = $this->typeInferencer->normalizeRules($structure['__rule__']);
            return $this->typeInferencer->inferFromValidationRules($ruleArray);
        }

        // This is an object with properties
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        foreach ($structure as $key => $value) {
            if ($key === '__rule__') {
                continue;
            }

            if (isset($value['__rule__'])) {
                // Leaf node
                $ruleArray = $this->typeInferencer->normalizeRules($value['__rule__']);
                $fieldSchema = $this->typeInferencer->inferFromValidationRules($ruleArray);
                $schema['properties'][$key] = $fieldSchema;

                if (in_array('required', $ruleArray) && !in_array('nullable', $ruleArray)) {
                    $schema['required'][] = $key;
                }
            } else {
                // Nested structure
                $schema['properties'][$key] = $this->buildNestedSchema($value);
            }
        }

        // Remove empty required array
        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }
}
