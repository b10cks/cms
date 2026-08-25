<?php

namespace App\Services\Documentation;

use Illuminate\Filesystem\Filesystem;

class OpenApiGenerator
{
    protected array $usedSchemas = [];

    public function __construct(
        private RouteAnalyzer $routeAnalyzer,
        private SchemaBuilder $schemaBuilder,
        private Filesystem $files
    ) {
    }

    /**
     * Generate OpenAPI documentation
     */
    public function generate(): array
    {
        $this->usedSchemas = [];
        $prefixes = $this->getConfiguredPrefixes();
        $routes = $this->routeAnalyzer->analyzeRoutes(array_keys($prefixes));

        $spec = $this->buildBaseStructure();

        $pathsByPrefix = [];

        foreach ($routes as $route) {
            $prefix = $route['prefix'];
            $path = '/' . $route['uri'];
            $method = $route['method'];

            if (!isset($pathsByPrefix[$prefix])) {
                $pathsByPrefix[$prefix] = [];
            }

            if (!isset($pathsByPrefix[$prefix][$path])) {
                $pathsByPrefix[$prefix][$path] = [];
            }

            $pathsByPrefix[$prefix][$path][$method] = $this->buildOperation($route);
        }

        $spec['paths'] = [];
        foreach ($pathsByPrefix as $prefix => $paths) {
            foreach ($paths as $path => $operations) {
                $spec['paths'][$path] = $operations;
            }
        }

        $allSchemas = $this->schemaBuilder->buildAllSchemas();
        $spec['components']['schemas'] = $this->filterSchemasForUsed($allSchemas);

        return $spec;
    }

    /**
     * Generate OpenAPI spec for a specific prefix
     */
    public function generateForPrefix(string $prefix): array
    {
        $this->usedSchemas = [];
        $prefixes = $this->getConfiguredPrefixes();

        if (!isset($prefixes[$prefix])) {
            return [];
        }

        $routes = $this->routeAnalyzer->analyzeRoutes([$prefix]);
        $spec = $this->buildBaseStructure($prefix);
        $spec['paths'] = [];

        foreach ($routes as $route) {
            $path = '/' . $route['uri'];
            $method = $route['method'];

            if (!isset($spec['paths'][$path])) {
                $spec['paths'][$path] = [];
            }

            $spec['paths'][$path][$method] = $this->buildOperation($route);
        }

        $allSchemas = $this->schemaBuilder->buildAllSchemas();
        $spec['components']['schemas'] = $this->filterSchemasForUsed($allSchemas);

        $security = $prefixes[$prefix]['security'] ?? [];
        if (!empty($security)) {
            $securityScheme = array_fill_keys($security, []);
            foreach ($spec['paths'] as &$pathItem) {
                foreach ($pathItem as &$operation) {
                    if (is_array($operation)) {
                        $operation['security'] = [$securityScheme];
                    }
                }
            }
        }

        return $spec;
    }

    /**
     * Write OpenAPI specs to files
     */
    public function writeToFiles(): array
    {
        $prefixes = $this->getConfiguredPrefixes();
        $outputDir = config('docs.output.directory', public_path('docs'));
        $perPrefix = config('docs.output.per_prefix', true);

        if (!$this->files->isDirectory($outputDir)) {
            $this->files->makeDirectory($outputDir, 0755, true);
        }

        $files = [];

        if ($perPrefix) {
            foreach (array_keys($prefixes) as $prefix) {
                $spec = $this->generateForPrefix($prefix);
                $filename = str_replace('/', '_', $prefix) . '.json';
                $filepath = $outputDir . '/' . $filename;

                $this->files->put($filepath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $files[$prefix] = $filepath;
            }
        } else {
            $spec = $this->generate();
            $filepath = $outputDir . '/openapi.json';

            $this->files->put($filepath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $files['all'] = $filepath;
        }

        return $files;
    }

    /**
     * Build base OpenAPI 3.0 structure
     */
    protected function buildBaseStructure(?string $prefix = null): array
    {
        $config = config('docs');
        $prefixes = $this->getConfiguredPrefixes();

        $title = $config['info']['title'] ?? 'API';
        $description = $config['info']['description'] ?? '';

        if ($prefix && isset($prefixes[$prefix]['title'])) {
            $title = $prefixes[$prefix]['title'];
            $description = $prefixes[$prefix]['description'] ?? $description;
        }

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $title,
                'description' => $description,
                'version' => $config['info']['version'] ?? '1.0.0',
                'contact' => $config['info']['contact'] ?? [],
            ],
            'servers' => [
                [
                    'url' => config('app.url'),
                    'description' => 'Current environment',
                ],
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => $this->buildSecuritySchemes(),
            ],
        ];
    }

    /**
     * Build security schemes from config
     */
    protected function buildSecuritySchemes(): array
    {
        $config = config('docs.security_schemes', []);
        $schemes = [];

        foreach ($config as $name => $scheme) {
            if (($scheme['type'] ?? null) === 'apiKey') {
                $schemes[$name] = [
                    'type' => 'apiKey',
                    'in' => $scheme['in'] ?? 'query',
                    'name' => $scheme['name'] ?? 'api_key',
                ];

                if (isset($scheme['description'])) {
                    $schemes[$name]['description'] = $scheme['description'];
                }
            } elseif (($scheme['type'] ?? null) === 'http') {
                $schemes[$name] = [
                    'type' => 'http',
                    'scheme' => $scheme['scheme'] ?? 'bearer',
                ];

                if (isset($scheme['description'])) {
                    $schemes[$name]['description'] = $scheme['description'];
                }
            }
        }

        return $schemes;
    }

    /**
     * Build operation (GET, POST, etc) for a route
     */
    protected function buildOperation(array $route): array
    {
        $operation = [
            'summary' => $route['summary'] ?? 'API endpoint',
            'description' => $route['description'] ?? '',
            'tags' => $route['tags'] ?? [],
            'operationId' => $this->generateOperationId($route),
        ];

        $parameters = $this->mergeParameters(
            $route['parameters'] ?? [],
            $route['queryParameters'] ?? [],
        );

        if (!empty($parameters)) {
            $operation['parameters'] = array_values($parameters);
        }

        if (!empty($route['requestBody'])) {
            $operation['requestBody'] = $this->convertSchemasToRefs($route['requestBody']);
        }

        if (!empty($route['responses'])) {
            $operation['responses'] = $this->convertResponseSchemasToRefs($route['responses']);
        } else {
            $operation['responses'] = [
                '200' => ['description' => 'Successful response'],
            ];
        }

        $prefixes = $this->getConfiguredPrefixes();
        $prefix = $route['prefix'];

        if (isset($prefixes[$prefix]['security'])) {
            $security = $prefixes[$prefix]['security'];

            if (!empty($security)) {
                $operation['security'] = [array_fill_keys($security, [])];
            } elseif ($security === [] || $security === false) {
                $operation['security'] = [];
            }
        }

        return $operation;
    }

    /**
     * Merge path/query parameter definitions while preserving richer details.
     *
     * Parameters are keyed by "in:name". When duplicates exist, the more complete
     * version wins and missing metadata is merged into the final output.
     */
    protected function mergeParameters(array ...$parameterGroups): array
    {
        $merged = [];

        foreach ($parameterGroups as $group) {
            foreach ($group as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $name = $parameter['name'] ?? null;
                $in = $parameter['in'] ?? null;

                if (!$name || !$in) {
                    continue;
                }

                $key = $in . ':' . $name;

                if (!isset($merged[$key])) {
                    $merged[$key] = $parameter;
                    continue;
                }

                $merged[$key] = $this->mergeParameter($merged[$key], $parameter);
            }
        }

        return $merged;
    }

    /**
     * Merge two parameter definitions into one richer representation.
     */
    protected function mergeParameter(array $base, array $incoming): array
    {
        $result = $base;

        $result['name'] = $incoming['name'] ?? $base['name'] ?? null;
        $result['in'] = $incoming['in'] ?? $base['in'] ?? null;
        $result['required'] = ($base['required'] ?? false) || ($incoming['required'] ?? false);

        if (!isset($result['description']) || $this->isDescriptionBetter($incoming['description'] ?? null, $result['description'] ?? null)) {
            if (!empty($incoming['description'])) {
                $result['description'] = $incoming['description'];
            }
        }

        if (isset($base['schema']) || isset($incoming['schema'])) {
            $result['schema'] = $this->mergeSchemaDetails(
                $base['schema'] ?? [],
                $incoming['schema'] ?? [],
            );
        }

        foreach (['example', 'examples', 'deprecated', 'style', 'explode', 'allowEmptyValue', 'allowReserved'] as $field) {
            if (!array_key_exists($field, $result) && array_key_exists($field, $incoming)) {
                $result[$field] = $incoming[$field];
            }
        }

        return $result;
    }

    /**
     * Merge schema fragments while preferring the richer definition.
     */
    protected function mergeSchemaDetails(array $base, array $incoming): array
    {
        if (empty($base)) {
            return $incoming;
        }

        if (empty($incoming)) {
            return $base;
        }

        $result = $base;

        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
                continue;
            }

            if ($key === 'description') {
                if ($this->isDescriptionBetter($value, $result[$key])) {
                    $result[$key] = $value;
                }
                continue;
            }

            if ($key === 'enum' && is_array($value) && is_array($result[$key])) {
                $result[$key] = array_values(array_unique([...$result[$key], ...$value], SORT_REGULAR));
                continue;
            }

            if ($key === 'oneOf' && is_array($value) && is_array($result[$key])) {
                $result[$key] = array_values(array_merge($result[$key], $value));
                continue;
            }

            if ($key === 'items' && is_array($value) && is_array($result[$key])) {
                $result[$key] = $this->mergeSchemaDetails($result[$key], $value);
                continue;
            }

            if ($key === 'properties' && is_array($value) && is_array($result[$key])) {
                foreach ($value as $propertyName => $propertySchema) {
                    if (isset($result[$key][$propertyName]) && is_array($result[$key][$propertyName]) && is_array($propertySchema)) {
                        $result[$key][$propertyName] = $this->mergeSchemaDetails($result[$key][$propertyName], $propertySchema);
                    } else {
                        $result[$key][$propertyName] = $propertySchema;
                    }
                }
                continue;
            }

            if (in_array($key, ['required'], true) && is_array($value) && is_array($result[$key])) {
                $result[$key] = array_values(array_unique([...$result[$key], ...$value]));
                continue;
            }

            if ($this->isValueMoreInformative($value, $result[$key])) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    protected function isDescriptionBetter(mixed $candidate, mixed $current): bool
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return false;
        }

        if (!is_string($current) || trim($current) === '') {
            return true;
        }

        return mb_strlen(trim($candidate)) > mb_strlen(trim($current));
    }

    protected function isValueMoreInformative(mixed $candidate, mixed $current): bool
    {
        if ($current === null || $current === '' || $current === []) {
            return true;
        }

        if ($candidate === null || $candidate === '' || $candidate === []) {
            return false;
        }

        if (is_array($candidate) && is_array($current)) {
            return count($candidate) > count($current);
        }

        if (is_string($candidate) && is_string($current)) {
            return mb_strlen($candidate) > mb_strlen($current);
        }

        return false;
    }

    /**
     * Generate unique operation ID from the HTTP method and the full route
     * path (minus the version prefix). Path parameters contribute a "by_x"
     * segment so sibling routes like "tokens" and "tokens/{token}" stay
     * distinct: `delete_spaces_tokens_by_token`.
     */
    protected function generateOperationId(array $route): string
    {
        $parts = explode('/', trim($route['uri'], '/'));

        // Drop the "api/v1"-style prefix — it is already implied by the spec file
        $parts = array_slice($parts, 2);

        $segments = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
                $segments[] = 'by_' . $matches[1];
            } else {
                $segments[] = str_replace(['-', '.'], '_', $part);
            }
        }

        $method = strtolower($route['method']);
        $path = implode('_', $segments) ?: 'root';

        return "{$method}_{$path}";
    }

    /**
     * Convert inline schemas in request body to $ref references
     */
    protected function convertSchemasToRefs(array $requestBody): array
    {
        if (isset($requestBody['content'])) {
            foreach ($requestBody['content'] as &$content) {
                if (isset($content['schema']) && is_array($content['schema'])) {
                    $content['schema'] = $this->convertSchemaToRef($content['schema']);
                }
            }
        }

        return $requestBody;
    }

    /**
     * Convert response schemas to $ref references
     */
    protected function convertResponseSchemasToRefs(array $responses): array
    {
        foreach ($responses as &$response) {
            if (isset($response['content'])) {
                foreach ($response['content'] as &$content) {
                    if (isset($content['schema']) && is_array($content['schema'])) {
                        $content['schema'] = $this->convertSchemaToRef($content['schema']);
                    }
                }
            }
        }

        return $responses;
    }

    /**
     * Recursively convert inline schema to $ref if it matches a component schema
     */
    protected function convertSchemaToRef(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $this->trackSchemaUsageFromRef($schema['$ref']);
            unset($schema['x-resource-class']);

            return $schema;
        }

        if (
            isset($schema['properties']['data']) &&
            isset($schema['properties']['links']) &&
            isset($schema['properties']['meta'])
        ) {
            if (
                isset($schema['properties']['data']['items']) &&
                isset($schema['properties']['data']['items']['x-resource-class'])
            ) {
                $resourceClass = $schema['properties']['data']['items']['x-resource-class'];
                unset($schema['properties']['data']['items']['x-resource-class']);

                $schemaName = class_basename($resourceClass);
                $this->trackSchemaUsage($schemaName);

                $schema['properties']['data']['items'] = [
                    '$ref' => "#/components/schemas/{$schemaName}",
                ];
            }

            unset($schema['x-resource-class']);

            return $schema;
        }

        if (isset($schema['x-resource-class'])) {
            $resourceClass = $schema['x-resource-class'];
            unset($schema['x-resource-class']);

            $schemaName = class_basename($resourceClass);
            $this->trackSchemaUsage($schemaName);

            return ['$ref' => "#/components/schemas/{$schemaName}"];
        }

        return $schema;
    }

    /**
     * Track schema usage from a $ref
     */
    protected function trackSchemaUsageFromRef(string $ref): void
    {
        if (preg_match('#/components/schemas/(.+)$#', $ref, $matches)) {
            $this->usedSchemas[$matches[1]] = true;
        }
    }

    /**
     * Track that a schema is used
     */
    protected function trackSchemaUsage(string $schemaName): void
    {
        $this->usedSchemas[$schemaName] = true;
    }

    /**
     * Filter schemas to only include used ones
     */
    protected function filterSchemasForUsed(array $allSchemas): array
    {
        $filtered = [];

        foreach ($this->usedSchemas as $schemaName => $used) {
            if ($used && isset($allSchemas[$schemaName])) {
                $filtered[$schemaName] = $allSchemas[$schemaName];
            }
        }

        $commonSchemas = ['PaginatedResponse', 'Error', 'ValidationError'];
        foreach ($commonSchemas as $schemaName) {
            if (isset($allSchemas[$schemaName]) && !isset($filtered[$schemaName])) {
                $filtered[$schemaName] = $allSchemas[$schemaName];
            }
        }

        return $this->withReferencedSchemas($filtered, $allSchemas);
    }

    /**
     * Pull in schemas the kept ones reference themselves, so an alias like
     * ContentVersionResource -> ContentVersionListResource does not leave a
     * dangling $ref.
     */
    protected function withReferencedSchemas(array $filtered, array $allSchemas): array
    {
        do {
            $added = false;

            foreach ($filtered as $schema) {
                foreach ($this->collectSchemaRefs($schema) as $schemaName) {
                    if (!isset($filtered[$schemaName]) && isset($allSchemas[$schemaName])) {
                        $filtered[$schemaName] = $allSchemas[$schemaName];
                        $added = true;
                    }
                }
            }
        } while ($added);

        return $filtered;
    }

    /**
     * Names of every component schema referenced anywhere inside a schema
     */
    protected function collectSchemaRefs(array $schema): array
    {
        $names = [];

        array_walk_recursive($schema, function ($value, $key) use (&$names) {
            if ($key === '$ref' && is_string($value) && preg_match('#^\#/components/schemas/(.+)$#', $value, $matches)) {
                $names[] = $matches[1];
            }
        });

        return $names;
    }

    /**
     * Get configured route prefixes
     */
    protected function getConfiguredPrefixes(): array
    {
        $config = config('docs.routes.prefixes', []);

        return !empty($config) ? $config : [
            'api/v1' => ['security' => ['tokenAuth']],
            'mgmt/v1' => ['security' => ['bearerAuth']],
            'auth/v1' => ['security' => []],
        ];
    }
}
