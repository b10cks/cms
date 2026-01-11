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
    ) {}

    /**
     * Generate OpenAPI documentation
     */
    public function generate(): array
    {
        $this->usedSchemas = [];
        $prefixes = $this->getConfiguredPrefixes();
        $routes = $this->routeAnalyzer->analyzeRoutes(array_keys($prefixes));

        // Build base OpenAPI structure
        $spec = $this->buildBaseStructure();

        // Build paths from routes
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

        // Add paths to spec
        $spec['paths'] = [];
        foreach ($pathsByPrefix as $prefix => $paths) {
            foreach ($paths as $path => $operations) {
                $spec['paths'][$path] = $operations;
            }
        }

        // Build component schemas - only used ones
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

        // Build base OpenAPI structure with prefix-specific config
        $spec = $this->buildBaseStructure($prefix);

        // Build paths from routes
        $spec['paths'] = [];

        foreach ($routes as $route) {
            $path = '/' . $route['uri'];
            $method = $route['method'];

            if (!isset($spec['paths'][$path])) {
                $spec['paths'][$path] = [];
            }

            $spec['paths'][$path][$method] = $this->buildOperation($route);
        }

        // Build component schemas - only used ones
        $allSchemas = $this->schemaBuilder->buildAllSchemas();
        $spec['components']['schemas'] = $this->filterSchemasForUsed($allSchemas);

        // Apply security scheme for this prefix
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

        // Ensure directory exists
        if (!$this->files->isDirectory($outputDir)) {
            $this->files->makeDirectory($outputDir, 0755, true);
        }

        $files = [];

        if ($perPrefix) {
            // Generate separate file per prefix
            foreach (array_keys($prefixes) as $prefix) {
                $spec = $this->generateForPrefix($prefix);
                $filename = str_replace('/', '_', $prefix) . '.json';
                $filepath = $outputDir . '/' . $filename;

                $this->files->put($filepath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $files[$prefix] = $filepath;
            }
        } else {
            // Generate single file with all routes
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

        // Use prefix-specific title if available
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
            if ($scheme['type'] === 'apiKey') {
                $schemes[$name] = [
                    'type' => 'apiKey',
                    'in' => $scheme['in'] ?? 'query',
                    'name' => $scheme['name'] ?? 'api_key',
                ];

                if (isset($scheme['description'])) {
                    $schemes[$name]['description'] = $scheme['description'];
                }
            } elseif ($scheme['type'] === 'http') {
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

        // Add parameters
        if (!empty($route['parameters']) || !empty($route['queryParameters'])) {
            $operation['parameters'] = array_merge(
                $route['parameters'] ?? [],
                $route['queryParameters'] ?? []
            );
        }

        // Add request body - convert to refs
        if (!empty($route['requestBody'])) {
            $operation['requestBody'] = $this->convertSchemasToRefs($route['requestBody']);
        }

        // Add responses - convert to refs
        if (!empty($route['responses'])) {
            $operation['responses'] = $this->convertResponseSchemasToRefs($route['responses']);
        } else {
            $operation['responses'] = [
                '200' => ['description' => 'Successful response'],
            ];
        }

        // Add security if not already in path item
        $prefixes = $this->getConfiguredPrefixes();
        $prefix = $route['prefix'];

        if (isset($prefixes[$prefix]['security'])) {
            $security = $prefixes[$prefix]['security'];
            if (!empty($security)) {
                $operation['security'] = [array_fill_keys($security, [])];
            } elseif ($security === [] || $security === false) {
                // Explicitly no security required
                $operation['security'] = [];
            }
        }

        return $operation;
    }

    /**
     * Generate unique operation ID
     */
    protected function generateOperationId(array $route): string
    {
        $parts = explode('/', trim($route['uri'], '/'));
        $resource = $parts[count($parts) - 1] ?? 'resource';

        $method = strtolower($route['method']);
        $name = $route['controllerMethod'] ?? $method;

        return "{$method}_{$resource}";
    }

    /**
     * Convert inline schemas in request body to $ref references
     */
    protected function convertSchemasToRefs(array $requestBody): array
    {
        if (isset($requestBody['content'])) {
            foreach ($requestBody['content'] as $contentType => &$content) {
                if (isset($content['schema'])) {
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
        foreach ($responses as $statusCode => &$response) {
            if (isset($response['content'])) {
                foreach ($response['content'] as $contentType => &$content) {
                    if (isset($content['schema'])) {
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
        // If already a ref, return as-is
        if (isset($schema['$ref'])) {
            $this->trackSchemaUsageFromRef($schema['$ref']);
            // Clean internal hints
            unset($schema['x-resource-class']);
            return $schema;
        }

        // Check if this is a paginated response (has data, links, meta structure)
        if (isset($schema['properties']['data']) && isset($schema['properties']['links']) && isset($schema['properties']['meta'])) {
            // Convert the items inside the data array
            if (isset($schema['properties']['data']['items']) && isset($schema['properties']['data']['items']['x-resource-class'])) {
                $resourceClass = $schema['properties']['data']['items']['x-resource-class'];
                unset($schema['properties']['data']['items']['x-resource-class']);

                $schemaName = class_basename($resourceClass);
                $this->trackSchemaUsage($schemaName);

                $schema['properties']['data']['items'] = ['$ref' => "#/components/schemas/{$schemaName}"];
            }

            // Clean internal hints from top level
            unset($schema['x-resource-class']);
            return $schema;
        }

        // Check if this schema has a resource class hint (single resource response)
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
        // Extract schema name from ref like "#/components/schemas/BlockResource"
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

        // Always include common schemas
        $commonSchemas = ['PaginatedResponse', 'Error', 'ValidationError'];
        foreach ($commonSchemas as $schemaName) {
            if (isset($allSchemas[$schemaName]) && !isset($filtered[$schemaName])) {
                $filtered[$schemaName] = $allSchemas[$schemaName];
            }
        }

        return $filtered;
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
