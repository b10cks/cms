<?php

namespace App\Services\Documentation\Parsers;

use ReflectionClass;
use ReflectionMethod;

class FilterParser
{
    /**
     * Parse a Filter class to extract query parameters.
     *
     * Supported annotations on filter classes / methods:
     * - @filterDescription Text
     * - @filterType string|integer|number|boolean|array|object
     * - @filterFormat date|date-time|uuid|uri|email
     * - @filterExample value
     * - @filterEnum a,b,c
     * - @filterArrayItemType string|integer|number|boolean|object
     * - @filterStyle form|spaceDelimited|pipeDelimited|deepObject
     * - @filterExplode true|false
     * - @filterDeprecated true|false
     *
     * Method-level annotations override class-level defaults.
     */
    public function parse(string $filterClass): array
    {
        if (!class_exists($filterClass)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($filterClass);
            $parameters = [];
            $classMetadata = $this->parseDocMetadata($reflection->getDocComment() ?: '');

            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() !== $filterClass) {
                    continue;
                }

                $methodName = $method->getName();

                if (in_array($methodName, ['__construct', '__call', '__get', '__set', 'handle', 'builder'])) {
                    continue;
                }

                if ($this->isFilterMethod($method)) {
                    $parameters[] = $this->analyzeFilterMethod($method, $filterClass, $classMetadata);
                }
            }

            if ($reflection->hasProperty('sortableColumns')) {
                $property = $reflection->getProperty('sortableColumns');
                $property->setAccessible(true);

                try {
                    $sortableColumns = null;

                    if ($property->isStatic()) {
                        $sortableColumns = $property->getValue();
                    } else {
                        try {
                            if (is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class)) {
                                $instance = new $filterClass([]);
                            } else {
                                $instance = new $filterClass(null);
                            }

                            $sortableColumns = $property->getValue($instance);
                        } catch (\Exception $e) {
                            $sortableColumns = $property->getDefaultValue();
                        }
                    }

                    if (!empty($sortableColumns) && is_array($sortableColumns)) {
                        $sortOptions = [];
                        foreach ($sortableColumns as $column) {
                            $sortOptions[] = $column;
                            $sortOptions[] = "-{$column}";
                        }

                        $sortDescription = $classMetadata['sortDescription']
                            ?? $classMetadata['description.sort']
                            ?? 'Sort by field (prefix with - for descending order)';

                        $parameter = [
                            'name' => 'sort',
                            'in' => 'query',
                            'required' => false,
                            'schema' => [
                                'type' => 'string',
                                'enum' => $sortOptions,
                            ],
                            'description' => $sortDescription,
                        ];

                        if (isset($classMetadata['deprecated.sort'])) {
                            $parameter['deprecated'] = $this->toBoolean($classMetadata['deprecated.sort']);
                        }

                        if (isset($classMetadata['example.sort'])) {
                            $parameter['example'] = $this->normalizeExampleValue($classMetadata['example.sort']);
                        }

                        $parameters[] = $parameter;
                    }
                } catch (\Exception $e) {
                    // Ignore inaccessible sort metadata
                }
            }

            return $parameters;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if method looks like a filter method.
     */
    protected function isFilterMethod(ReflectionMethod $method): bool
    {
        if ($method->getNumberOfParameters() !== 1) {
            return false;
        }

        $params = $method->getParameters();

        return !empty($params) && $params[0]->getName() === 'value';
    }

    /**
     * Analyze a single filter method to extract parameter info.
     *
     * @param  array<string, mixed>  $classMetadata
     * @return array<string, mixed>
     */
    protected function analyzeFilterMethod(ReflectionMethod $method, string $filterClass, array $classMetadata = []): array
    {
        $methodName = $method->getName();
        $methodBody = $this->getMethodBody($method);
        $methodMetadata = $this->parseDocMetadata($method->getDocComment() ?: '');
        $metadata = array_merge($classMetadata, $methodMetadata);

        $schema = $this->inferFilterParameterType($methodBody, $filterClass);
        $schema = $this->applyMetadataToSchema($schema, $metadata, $methodName);

        $parameter = [
            'name' => $methodName,
            'in' => 'query',
            'required' => false,
            'schema' => $schema,
            'description' => $this->resolveFilterDescription($methodName, $methodBody, $filterClass, $metadata),
        ];

        if (isset($metadata['style'])) {
            $parameter['style'] = (string) $metadata['style'];
        }

        if (isset($metadata['explode'])) {
            $parameter['explode'] = $this->toBoolean($metadata['explode']);
        }

        if (isset($metadata['deprecated'])) {
            $parameter['deprecated'] = $this->toBoolean($metadata['deprecated']);
        }

        if (isset($metadata['example'])) {
            $parameter['example'] = $this->normalizeExampleValue($metadata['example']);
        } elseif (isset($schema['example'])) {
            $parameter['example'] = $schema['example'];
        }

        return $parameter;
    }

    /**
     * Extract method body source code.
     */
    protected function getMethodBody(ReflectionMethod $method): string
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (!file_exists($filename)) {
                return '';
            }

            $lines = file($filename);

            return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Infer filter parameter type by analyzing method body.
     *
     * @return array<string, mixed>
     */
    protected function inferFilterParameterType(string $methodBody, string $filterClass = ''): array
    {
        $isAdvancedFilter = !empty($filterClass)
            && class_exists($filterClass)
            && is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class);

        if ($isAdvancedFilter && (str_contains($methodBody, 'applyAdvancedRangeFilter') || str_contains($methodBody, 'applyAdvancedDateFilter'))) {
            return [
                'type' => 'string',
                'description' => 'Supports: value, range (value1...value2), operators (>=value, >value, <=value, <value, <>value)',
            ];
        }

        if (str_contains($methodBody, 'is_array') || str_contains($methodBody, 'whereIn')) {
            return [
                'oneOf' => [
                    ['type' => 'string'],
                    [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ];
        }

        if (str_contains($methodBody, 'whereJsonContains')) {
            return [
                'oneOf' => [
                    ['type' => 'string'],
                    [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ];
        }

        if (str_contains($methodBody, 'RangeFilter') || str_contains($methodBody, 'applyRange')) {
            return [
                'type' => 'string',
                'description' => 'Single value or range (format: value1...value2)',
            ];
        }

        if (str_contains($methodBody, 'LIKE')) {
            return ['type' => 'string'];
        }

        if (str_contains($methodBody, 'whereNull')) {
            return [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'null'],
                ],
            ];
        }

        if (
            str_contains($methodBody, "=== 'true'")
            || str_contains($methodBody, '=== "true"')
            || str_contains($methodBody, "=== 'false'")
            || str_contains($methodBody, '=== "false"')
            || str_contains($methodBody, 'whereNotNull')
        ) {
            return ['type' => 'boolean'];
        }

        return ['type' => 'string'];
    }

    /**
     * Generate helpful description for filter parameter.
     */
    protected function generateFilterDescription(string $methodName, string $methodBody, string $filterClass = ''): string
    {
        $descriptions = [
            'q' => 'Free-text search filter.',
            'name' => 'Filter by name (LIKE search)',
            'slug' => 'Filter by slug (LIKE search)',
            'title' => 'Filter by title (LIKE search)',
            'type' => 'Filter by type',
            'status' => 'Filter by status',
            'state' => 'Filter by state',
            'created_at' => 'Filter by creation date (supports operators and ranges)',
            'updated_at' => 'Filter by update date (supports operators and ranges)',
            'published_at' => 'Filter by publication date (supports operators and ranges)',
            'deleted_at' => 'Filter by deletion date',
            'parent_id' => 'Filter by parent ID',
            'folder_id' => 'Filter by folder ID',
            'space_id' => 'Filter by space ID',
            'published' => 'Filter by published status',
            'enabled' => 'Filter by enabled status',
            'active' => 'Filter by active status',
            'language' => 'Filter by language code',
            'language_iso' => 'Filter by language code',
        ];

        if (isset($descriptions[$methodName])) {
            return $descriptions[$methodName];
        }

        $isAdvancedFilter = !empty($filterClass)
            && class_exists($filterClass)
            && is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class);

        if ($isAdvancedFilter && str_contains($methodBody, 'applyAdvancedRangeFilter')) {
            return "Filter by {$methodName} with advanced range support (value, value1...value2, >=value, >value, <=value, <value, <>value)";
        }

        if ($isAdvancedFilter && str_contains($methodBody, 'applyAdvancedDateFilter')) {
            return "Filter by {$methodName} date with operators (>=value, >value, <=value, <value or range: value1...value2)";
        }

        if (str_contains($methodBody, 'LIKE')) {
            return "Filter by {$methodName} (LIKE search)";
        }

        if (str_contains($methodBody, 'whereIn')) {
            return "Filter by {$methodName} (accepts single value or array)";
        }

        if (str_contains($methodBody, 'whereNull')) {
            return "Filter by {$methodName} (check if null)";
        }

        if (str_contains($methodBody, 'applyRange')) {
            return "Filter by {$methodName} with range syntax (value1...value2)";
        }

        return "Filter by {$methodName}";
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function resolveFilterDescription(
        string $methodName,
        string $methodBody,
        string $filterClass,
        array $metadata
    ): string {
        if (!empty($metadata['description'])) {
            return (string) $metadata['description'];
        }

        if (!empty($metadata["description.{$methodName}"])) {
            return (string) $metadata["description.{$methodName}"];
        }

        return $this->generateFilterDescription($methodName, $methodBody, $filterClass);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function applyMetadataToSchema(array $schema, array $metadata, string $methodName): array
    {
        $type = $metadata['type'] ?? $metadata["type.{$methodName}"] ?? null;
        $format = $metadata['format'] ?? $metadata["format.{$methodName}"] ?? null;
        $enum = $metadata['enum'] ?? $metadata["enum.{$methodName}"] ?? null;
        $example = $metadata['example'] ?? $metadata["example.{$methodName}"] ?? null;
        $itemType = $metadata['arrayItemType'] ?? $metadata["arrayItemType.{$methodName}"] ?? null;

        if ($type !== null) {
            $schema = $this->forceSchemaType((string) $type, $schema, $itemType);
        }

        if ($format !== null) {
            if (isset($schema['type']) && $schema['type'] === 'string') {
                $schema['format'] = (string) $format;
            } elseif (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
                foreach ($schema['oneOf'] as &$variant) {
                    if (($variant['type'] ?? null) === 'string') {
                        $variant['format'] = (string) $format;
                    }
                }
                unset($variant);
            }
        }

        if ($enum !== null) {
            $enumValues = $this->parseEnumValues((string) $enum);

            if (isset($schema['type'])) {
                $schema['enum'] = $enumValues;
            } elseif (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
                foreach ($schema['oneOf'] as &$variant) {
                    if (($variant['type'] ?? null) === 'string') {
                        $variant['enum'] = $enumValues;
                    }
                }
                unset($variant);
            }
        }

        if ($example !== null) {
            $schema['example'] = $this->normalizeExampleValue($example);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function forceSchemaType(string $type, array $schema, mixed $itemType = null): array
    {
        $normalizedType = strtolower(trim($type));

        return match ($normalizedType) {
            'string', 'integer', 'number', 'boolean', 'object' => ['type' => $normalizedType],
            'array' => [
                'type' => 'array',
                'items' => [
                    'type' => $this->normalizeArrayItemType($itemType),
                ],
            ],
            default => $schema,
        };
    }

    protected function normalizeArrayItemType(mixed $itemType): string
    {
        $type = strtolower(trim((string) ($itemType ?? 'string')));

        return in_array($type, ['string', 'integer', 'number', 'boolean', 'object'], true)
            ? $type
            : 'string';
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseDocMetadata(string $docComment): array
    {
        $metadata = [];

        if ($docComment === '') {
            return $metadata;
        }

        $patterns = [
            'description' => '/@filterDescription\s+(.+)/',
            'type' => '/@filterType\s+([^\s]+)/',
            'format' => '/@filterFormat\s+([^\s]+)/',
            'example' => '/@filterExample\s+(.+)/',
            'enum' => '/@filterEnum\s+(.+)/',
            'arrayItemType' => '/@filterArrayItemType\s+([^\s]+)/',
            'style' => '/@filterStyle\s+([^\s]+)/',
            'explode' => '/@filterExplode\s+([^\s]+)/',
            'deprecated' => '/@filterDeprecated\s+([^\s]+)/',
            'sortDescription' => '/@sortDescription\s+(.+)/',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $docComment, $matches)) {
                $metadata[$key] = trim($matches[1]);
            }
        }

        preg_match_all('/@filterDescription\.([A-Za-z0-9_\-]+)\s+(.+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['description.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterType\.([A-Za-z0-9_\-]+)\s+([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['type.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterFormat\.([A-Za-z0-9_\-]+)\s+([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['format.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterExample\.([A-Za-z0-9_\-]+)\s+(.+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['example.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterEnum\.([A-Za-z0-9_\-]+)\s+(.+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['enum.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterArrayItemType\.([A-Za-z0-9_\-]+)\s+([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['arrayItemType.' . $match[1]] = trim($match[2]);
        }

        preg_match_all('/@filterDeprecated\.([A-Za-z0-9_\-]+)\s+([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $metadata['deprecated.' . $match[1]] = trim($match[2]);
        }

        return $metadata;
    }

    /**
     * @return array<int, mixed>
     */
    protected function parseEnumValues(string $enum): array
    {
        $parts = array_map('trim', explode(',', $enum));
        $parts = array_values(array_filter($parts, static fn ($value) => $value !== ''));

        return array_map(fn ($value) => $this->normalizeExampleValue($value), $parts);
    }

    protected function normalizeExampleValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if ($trimmed === 'true') {
            return true;
        }

        if ($trimmed === 'false') {
            return false;
        }

        if ($trimmed === 'null') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        if (
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
            || (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
        ) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return trim($trimmed, "\"'");
    }

    protected function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
