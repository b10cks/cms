<?php

namespace App\Services\Documentation\Parsers;

use ReflectionClass;
use ReflectionMethod;

class FilterParser
{
    /**
     * Parse a Filter class to extract query parameters
     */
    public function parse(string $filterClass): array
    {
        if (!class_exists($filterClass)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($filterClass);
            $parameters = [];

            // Get all public methods
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                // Skip inherited methods (only process methods defined in this class)
                if ($method->getDeclaringClass()->getName() !== $filterClass) {
                    continue;
                }

                $methodName = $method->getName();

                // Skip special methods
                if (in_array($methodName, ['__construct', '__call', '__get', '__set', 'handle', 'builder'])) {
                    continue;
                }

                // Skip methods that don't match filter pattern
                if ($this->isFilterMethod($method)) {
                    $parameters[] = $this->analyzeFilterMethod($method, $filterClass);
                }
            }

            // Add sort parameter if sortableColumns exists
            if ($reflection->hasProperty('sortableColumns')) {
                $property = $reflection->getProperty('sortableColumns');
                $property->setAccessible(true);

                try {
                    // Try to get static property first, then create instance
                    $sortableColumns = null;

                    if ($property->isStatic()) {
                        $sortableColumns = $property->getValue();
                    } else {
                        // Try to instantiate with appropriate arguments
                        try {
                            if (is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class)) {
                                $instance = new $filterClass([]);
                            } else {
                                $instance = new $filterClass(null);
                            }
                            $sortableColumns = $property->getValue($instance);
                        } catch (\Exception $e) {
                            // Fallback: just try to get the default value
                            $sortableColumns = $property->getDefaultValue();
                        }
                    }

                    if (!empty($sortableColumns) && is_array($sortableColumns)) {
                        // Create both ascending and descending options
                        $sortOptions = [];
                        foreach ($sortableColumns as $column) {
                            $sortOptions[] = $column;
                            $sortOptions[] = "-{$column}";
                        }

                        $parameters[] = [
                            'name' => 'sort',
                            'in' => 'query',
                            'required' => false,
                            'schema' => [
                                'type' => 'string',
                                'enum' => $sortOptions,
                            ],
                            'description' => 'Sort by field (prefix with - for descending order)',
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip if sortableColumns can't be accessed
                }
            }

            return $parameters;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if method looks like a filter method
     */
    protected function isFilterMethod(ReflectionMethod $method): bool
    {
        // Must have exactly 1 parameter
        if ($method->getNumberOfParameters() !== 1) {
            return false;
        }

        // Parameter should be named $value
        $params = $method->getParameters();
        if (empty($params) || $params[0]->getName() !== 'value') {
            return false;
        }

        return true;
    }

    /**
     * Analyze a single filter method to extract parameter info
     */
    protected function analyzeFilterMethod(ReflectionMethod $method, string $filterClass): array
    {
        $methodName = $method->getName();
        $methodBody = $this->getMethodBody($method);

        $paramType = $this->inferFilterParameterType($methodBody, $filterClass);

        return [
            'name' => $methodName,
            'in' => 'query',
            'required' => false,
            'schema' => $paramType,
            'description' => $this->generateFilterDescription($methodName, $methodBody, $filterClass),
        ];
    }

    /**
     * Extract method body source code
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
     * Infer filter parameter type by analyzing method body
     */
    protected function inferFilterParameterType(string $methodBody, string $filterClass = ''): array
    {
        $isAdvancedFilter = !empty($filterClass) && class_exists($filterClass) &&
                           is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class);

        // Detect advanced range filter (dates, numbers with operators)
        if ($isAdvancedFilter && (str_contains($methodBody, 'applyAdvancedRangeFilter') || str_contains($methodBody, 'applyAdvancedDateFilter'))) {
            return [
                'type' => 'string',
                'description' => 'Supports: value, range (value1...value2), operators (>=value, >value, <=value, <value, <>value)',
            ];
        }

        // Detect array handling: is_array($value) or whereIn()
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

        // Detect JSON contains
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

        // Detect range filter (for dates and numbers)
        if (str_contains($methodBody, 'RangeFilter') || str_contains($methodBody, 'applyRange')) {
            return [
                'type' => 'string',
                'description' => 'Single value or range (format: value1...value2)',
            ];
        }

        // Detect LIKE search
        if (str_contains($methodBody, 'LIKE')) {
            return ['type' => 'string'];
        }

        // Detect null handling
        if (str_contains($methodBody, 'whereNull')) {
            return [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'null'],
                ],
            ];
        }

        // Default to string
        return ['type' => 'string'];
    }

    /**
     * Generate helpful description for filter parameter
     */
    protected function generateFilterDescription(string $methodName, string $methodBody, string $filterClass = ''): string
    {
        // Common filter descriptions
        $descriptions = [
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
        ];

        if (isset($descriptions[$methodName])) {
            return $descriptions[$methodName];
        }

        // Detect advanced filter types
        $isAdvancedFilter = !empty($filterClass) && class_exists($filterClass) &&
                           is_subclass_of($filterClass, \CodersCantina\Filter\AdvancedFilter::class);

        if ($isAdvancedFilter && str_contains($methodBody, 'applyAdvancedRangeFilter')) {
            return "Filter by {$methodName} with advanced range support (value, value1...value2, >=value, >value, <=value, <value, <>value)";
        }

        if ($isAdvancedFilter && str_contains($methodBody, 'applyAdvancedDateFilter')) {
            return "Filter by {$methodName} date with operators (>=value, >value, <=value, <value or range: value1...value2)";
        }

        // Detect filter type from body
        if (str_contains($methodBody, 'LIKE')) {
            return "Filter by {$methodName} (LIKE search)";
        }

        if (str_contains($methodBody, 'whereIn')) {
            return "Filter by {$methodName} (accepts single value or array)";
        }

        if (str_contains($methodBody, 'whereNull')) {
            return "Filter by {$methodName} (check if null)";
        }

        return "Filter by {$methodName}";
    }
}
