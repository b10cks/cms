<?php

namespace App\Services\Documentation\Parsers;

use App\Models\Settings;
use App\Services\Documentation\TypeInferencer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionClass;
use ReflectionMethod;
use UnitEnum;

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
        if (! class_exists($formRequestClass) || ! is_subclass_of($formRequestClass, FormRequest::class)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($formRequestClass);

            try {
                $instance = new $formRequestClass();
                $rules = $instance->rules();
            } catch (\Throwable $e) {
                $rulesMethod = $reflection->getMethod('rules');
                $rules = $this->extractRulesFromMethod($rulesMethod, $formRequestClass);

                if (empty($rules)) {
                    return [];
                }
            }

            $schema = $this->buildSchemaFromRules($rules);

            return [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $schema,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Extract rules array from FormRequest rules() method by parsing source
     */
    protected function extractRulesFromMethod(ReflectionMethod $method, string $formRequestClass): array
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (! $filename || ! file_exists($filename)) {
                return [];
            }

            $lines = file($filename);
            $methodCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            if (trim($methodCode) === '') {
                return [];
            }

            return [];
        } catch (\Throwable $e) {
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

        $flatRules = [];
        $nestedRules = [];

        foreach ($rules as $field => $rule) {
            if (str_contains($field, '.')) {
                $nestedRules[$field] = $rule;
            } else {
                $flatRules[$field] = $rule;
            }
        }

        foreach ($flatRules as $field => $rule) {
            $ruleArray = $this->normalizeRules($rule);
            $fieldSchema = $this->typeInferencer->inferFromValidationRules($ruleArray);

            $this->applyRuleMetadata($fieldSchema, $ruleArray);
            $this->applyMetadata($fieldSchema, $field, $this->extractMetadataFromRules($ruleArray));

            $schema['properties'][$field] = $fieldSchema;

            if ($this->isRequiredRuleSet($ruleArray)) {
                $schema['required'][] = $field;
            }
        }

        if (! empty($nestedRules)) {
            $schema = $this->processNestedRules($schema, $nestedRules);
        }

        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Process nested validation rules (e.g. settings.ai.model or items.*.name)
     */
    protected function processNestedRules(array $schema, array $nestedRules): array
    {
        $tree = [];

        foreach ($nestedRules as $field => $rule) {
            $parts = explode('.', $field);
            $current = &$tree;

            foreach ($parts as $part) {
                if (! isset($current[$part])) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }

            $current['__rule__'] = $rule;
        }

        foreach ($tree as $key => $value) {
            $schema['properties'][$key] = $this->buildNestedSchema($value, $key);
        }

        return $schema;
    }

    /**
     * Build nested object/array schema from tree structure
     */
    protected function buildNestedSchema(array $structure, string $path = ''): array
    {
        if (isset($structure['*'])) {
            $itemsSchema = $this->buildNestedSchema($structure['*'], $this->joinPath($path, '*'));

            $schema = [
                'type' => 'array',
                'items' => $itemsSchema,
            ];

            if (isset($structure['__rule__'])) {
                $ruleArray = $this->normalizeRules($structure['__rule__']);
                $this->applyRuleMetadata($schema, $ruleArray);
                $this->applyMetadata($schema, $path, $this->extractMetadataFromRules($ruleArray));
            }

            return $schema;
        }

        if (isset($structure['__rule__']) && count($structure) === 1) {
            $ruleArray = $this->normalizeRules($structure['__rule__']);
            $schema = $this->typeInferencer->inferFromValidationRules($ruleArray);

            $this->applyRuleMetadata($schema, $ruleArray);
            $this->applyMetadata($schema, $path, $this->extractMetadataFromRules($ruleArray));

            return $schema;
        }

        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        if (isset($structure['__rule__'])) {
            $ruleArray = $this->normalizeRules($structure['__rule__']);
            $baseSchema = $this->typeInferencer->inferFromValidationRules($ruleArray);

            if (($baseSchema['type'] ?? null) === 'array') {
                $schema['type'] = 'array';
            }

            $this->applyRuleMetadata($schema, $ruleArray);
            $this->applyMetadata($schema, $path, $this->extractMetadataFromRules($ruleArray));
        }

        foreach ($structure as $key => $value) {
            if ($key === '__rule__') {
                continue;
            }

            $childPath = $this->joinPath($path, $key);
            $childSchema = $this->buildNestedSchema($value, $childPath);

            if ($schema['type'] === 'array') {
                if (! isset($schema['items']) || ! is_array($schema['items'])) {
                    $schema['items'] = [
                        'type' => 'object',
                        'properties' => [],
                        'required' => [],
                    ];
                }

                if ($key === '*') {
                    $schema['items'] = $childSchema;
                    continue;
                }

                if (($schema['items']['type'] ?? null) !== 'object') {
                    $schema['items'] = [
                        'type' => 'object',
                        'properties' => [],
                        'required' => [],
                    ];
                }

                $schema['items']['properties'][$key] = $childSchema;

                if (isset($value['__rule__'])) {
                    $ruleArray = $this->normalizeRules($value['__rule__']);
                    if ($this->isRequiredRuleSet($ruleArray)) {
                        $schema['items']['required'][] = $key;
                    }
                }
            } else {
                $schema['properties'][$key] = $childSchema;

                if (isset($value['__rule__'])) {
                    $ruleArray = $this->normalizeRules($value['__rule__']);
                    if ($this->isRequiredRuleSet($ruleArray)) {
                        $schema['required'][] = $key;
                    }
                }
            }
        }

        if (isset($schema['items']['required']) && empty($schema['items']['required'])) {
            unset($schema['items']['required']);
        }

        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Normalize rule input to a flat array suitable for inference and metadata extraction.
     *
     * @return array<int, mixed>
     */
    protected function normalizeRules(mixed $rule): array
    {
        $normalized = $this->typeInferencer->normalizeRules($rule);

        if (! is_array($normalized)) {
            return [];
        }

        return array_values($normalized);
    }

    /**
     * Apply metadata inferred from rule objects / strings.
     */
    protected function applyRuleMetadata(array &$schema, array $ruleArray): void
    {
        foreach ($ruleArray as $rule) {
            if (is_string($rule)) {
                $this->applyStringRuleMetadata($schema, $rule);
                continue;
            }

            if ($rule instanceof In) {
                $values = $this->extractInValues($rule);
                if (! empty($values)) {
                    $schema['enum'] = $values;
                }

                continue;
            }

            if ($rule instanceof Enum) {
                $enumClass = $this->extractEnumClass($rule);
                $values = $this->extractEnumValues($enumClass);

                if (! empty($values)) {
                    $schema['enum'] = $values;
                }

                continue;
            }
        }
    }

    protected function applyStringRuleMetadata(array &$schema, string $rule): void
    {
        if (str_starts_with($rule, 'in:')) {
            $values = $this->typeInferencer->parseInRuleValues($rule);
            if (! empty($values)) {
                $schema['enum'] = $values;
            }

            return;
        }

        if ($rule === 'email' || str_starts_with($rule, 'email:')) {
            $schema['format'] = 'email';
            return;
        }

        if ($rule === 'url' || str_starts_with($rule, 'url')) {
            $schema['format'] = 'uri';
            return;
        }

        if ($rule === 'uuid') {
            $schema['format'] = 'uuid';
            return;
        }

        if ($rule === 'ulid') {
            $schema['format'] = 'ulid';
            return;
        }

        if ($rule === 'date') {
            if (($schema['format'] ?? null) !== 'date-time') {
                $schema['format'] = 'date';
            }

            return;
        }

        if ($rule === 'date_format:c' || $rule === 'date_format:Y-m-d\TH:i:sP') {
            $schema['format'] = 'date-time';
            return;
        }

        if ($rule === 'array') {
            $schema['type'] = 'array';
            return;
        }

        if ($rule === 'boolean') {
            $schema['type'] = 'boolean';
            return;
        }

        if ($rule === 'integer') {
            $schema['type'] = 'integer';
            return;
        }

        if ($rule === 'numeric') {
            $schema['type'] = 'number';
            return;
        }

        if ($rule === 'string') {
            $schema['type'] = 'string';
        }
    }

    /**
     * Extract OpenAPI / documentation metadata from validation rules.
     *
     * Supported sources:
     * - Settings-derived metadata via static toValidator()
     * - array rules containing ['description' => ..., 'example' => ...] style metadata
     *
     * @return array<string, mixed>
     */
    protected function extractMetadataFromRules(array $ruleArray): array
    {
        $metadata = [];

        foreach ($ruleArray as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if ($this->isAssociativeArray($rule)) {
                foreach (['description', 'example', 'examples', 'format', 'nullable', 'deprecated', 'enumDescriptions'] as $key) {
                    if (array_key_exists($key, $rule)) {
                        $metadata[$key] = $rule[$key];
                    }
                }
            }
        }

        return $metadata;
    }

    /**
     * Apply external metadata to a schema node.
     */
    protected function applyMetadata(array &$schema, string $path, array $metadata): void
    {
        if ($path === '' || empty($metadata)) {
            return;
        }

        foreach (['description', 'example', 'examples', 'format', 'deprecated'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $schema[$key] = $metadata[$key];
            }
        }

        if (array_key_exists('nullable', $metadata)) {
            $schema['nullable'] = (bool) $metadata['nullable'];
        }

        if (array_key_exists('enumDescriptions', $metadata)) {
            $schema['x-enum-descriptions'] = $metadata['enumDescriptions'];
        }
    }

    protected function isRequiredRuleSet(array $ruleArray): bool
    {
        return in_array('required', $ruleArray, true) && ! in_array('nullable', $ruleArray, true);
    }

    protected function joinPath(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }

    /**
     * @return array<int, string|int|float|bool>
     */
    protected function extractInValues(In $rule): array
    {
        try {
            $reflection = new ReflectionClass($rule);

            if (! $reflection->hasProperty('values')) {
                return [];
            }

            $property = $reflection->getProperty('values');
            $property->setAccessible(true);

            $values = $property->getValue($rule);

            if (! is_array($values)) {
                return [];
            }

            return array_values(array_map(static function ($value) {
                return $value instanceof UnitEnum ? $value->value ?? $value->name : $value;
            }, $values));
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function extractEnumClass(Enum $rule): ?string
    {
        try {
            $reflection = new ReflectionClass($rule);

            if (! $reflection->hasProperty('type')) {
                return null;
            }

            $property = $reflection->getProperty('type');
            $property->setAccessible(true);

            $type = $property->getValue($rule);

            return is_string($type) ? $type : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string|int>
     */
    protected function extractEnumValues(?string $enumClass): array
    {
        if (! $enumClass || ! enum_exists($enumClass)) {
            return [];
        }

        $values = [];

        foreach ($enumClass::cases() as $case) {
            $values[] = property_exists($case, 'value') ? $case->value : $case->name;
        }

        return $values;
    }

    protected function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
