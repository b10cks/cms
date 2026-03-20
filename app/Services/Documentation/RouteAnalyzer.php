<?php

namespace App\Services\Documentation;

use App\Services\Documentation\Parsers\FilterParser;
use App\Services\Documentation\Parsers\FormRequestParser;
use App\Services\Documentation\Parsers\ResourceParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionMethod;

class RouteAnalyzer
{
    public function __construct(
        private FormRequestParser $formRequestParser,
        private FilterParser $filterParser,
        private ResourceParser $resourceParser,
        private TypeInferencer $typeInferencer,
    ) {
    }

    /**
     * Analyze all routes matching configured prefixes
     */
    public function analyzeRoutes(array $prefixes = []): array
    {
        $routes = RouteFacade::getRoutes();
        $analyzed = [];

        foreach ($routes as $route) {
            if (!$this->shouldIncludeRoute($route, $prefixes)) {
                continue;
            }

            try {
                $analysis = $this->analyzeRoute($route);
                if ($analysis) {
                    $analyzed[] = $analysis;
                }
            } catch (\Throwable $e) {
                // Skip routes that can't be analyzed - don't crash the entire generation
                // In verbose mode, this could be logged
                continue;
            }
        }

        return $analyzed;
    }

    /**
     * Check if route should be included based on configured prefixes
     */
    protected function shouldIncludeRoute(Route $route, array $prefixes): bool
    {
        $uri = $route->uri();

        // Skip non-API routes
        if ($uri === '/' || str_starts_with($uri, 'api/web')) {
            return false;
        }

        // If no prefixes specified, include all API routes
        if (empty($prefixes)) {
            return str_starts_with($uri, 'api/') ||
                str_starts_with($uri, 'mgmt/') ||
                str_starts_with($uri, 'auth/');
        }

        // Check against specified prefixes
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyze a single route
     */
    protected function analyzeRoute(Route $route): ?array
    {
        // Get controller and method
        $action = $route->getAction();

        if (empty($action['controller'])) {
            return null;
        }

        [$controller, $method] = $this->parseController($action['controller']);

        if (!$controller || !$method) {
            return null;
        }

        // Extract methods from route
        $methods = collect($route->methods())
            ->filter(fn($m) => !in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn($m) => strtolower($m))
            ->values()
            ->toArray();

        if (empty($methods)) {
            return null;
        }

        return [
            'method' => $methods[0], // Use first HTTP method
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'prefix' => $this->extractPrefix($route->uri()),
            'controller' => $controller,
            'controllerMethod' => $method,
            'middleware' => $route->middleware(),
            'parameters' => $this->extractPathParameters($controller, $method, $route),
            'queryParameters' => $this->extractQueryParameters($controller, $method, $methods[0]),
            'requestBody' => $this->extractRequestBody($controller, $method, $methods[0]),
            'responses' => $this->extractResponses($controller, $method),
            'summary' => $this->generateSummary($route),
            'description' => $this->generateDescription($controller, $method),
            'tags' => $this->extractTags($route),
        ];
    }

    /**
     * Parse controller string like "App\Http\Controllers\UserController@show"
     */
    protected function parseController(string $controller): ?array
    {
        if (str_contains($controller, '@')) {
            [$controllerClass, $method] = explode('@', $controller);
            return [trim($controllerClass), trim($method)];
        }

        // Handle class-based single action controllers
        if (class_exists($controller)) {
            return [$controller, '__invoke'];
        }

        return null;
    }

    /**
     * Extract path parameters from route and controller
     */
    protected function extractPathParameters(string $controller, string $method, Route $route): array
    {
        $parameters = [];

        try {
            $reflection = new ReflectionMethod($controller, $method);

            // Get route parameters from the URI
            preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
            $routeParams = $matches[1] ?? [];

            // Match with reflection parameters
            foreach ($reflection->getParameters() as $param) {
                $paramName = $param->getName();

                if (!in_array($paramName, $routeParams)) {
                    continue;
                }

                // Get parameter type
                $type = $param->getType() instanceof \ReflectionNamedType
                    ? $param->getType()->getName()
                    : null;

                // Try to infer from type hint
                if ($type && class_exists($type)) {
                    $paramType = $this->typeInferencer->inferFromModel($type);
                } else {
                    // Infer from name or default to string
                    $paramType = $this->typeInferencer->inferFromFieldName($paramName);
                }

                $parameters[] = [
                    'name' => $paramName,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $paramType,
                    'description' => "The {$paramName} identifier",
                ];
            }
        } catch (\Exception $e) {
            // Skip on error
        }

        return $parameters;
    }

    /**
     * Extract query parameters from Filter usage
     */
    protected function extractQueryParameters(string $controller, string $method, ?string $httpMethod = null): array
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);
            $filename = $reflection->getFileName();

            if (!file_exists($filename)) {
                return [];
            }

            // Read method source code
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();
            $lines = file($filename);
            $methodCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            $parameters = [];

            // Look for filter() method call with Filter class reference
            // Pattern: ->filter(ContentFilter::fromRequest($request)) or ::filter(ContentFilter::fromRequest($request))
            if (preg_match('/(?:->|::)filter\s*\(\s*([A-Za-z\\\]+Filter)::fromRequest/', $methodCode, $matches)) {
                $filterClass = $this->resolveFilterClass($matches[1]);

                if ($filterClass && class_exists($filterClass)) {
                    $parameters = array_merge($parameters, $this->filterParser->parse($filterClass));
                }
            }

            // Also try direct Filter::fromRequest pattern
            if (preg_match('/([A-Za-z\\\]+Filter)::fromRequest/', $methodCode, $matches)) {
                $filterClass = $this->resolveFilterClass($matches[1]);

                if ($filterClass && class_exists($filterClass)) {
                    $parameters = array_merge($parameters, $this->filterParser->parse($filterClass));
                }
            }

            if ($httpMethod === 'get') {
                foreach ($reflection->getParameters() as $param) {
                    $type = $param->getType() instanceof \ReflectionNamedType
                        ? $param->getType()->getName()
                        : null;

                    if (!$type) {
                        continue;
                    }

                    if (!class_exists($type) || !is_subclass_of($type, FormRequest::class)) {
                        continue;
                    }

                    try {
                        $requestBody = $this->formRequestParser->parse($type);
                        $schema = $requestBody['content']['application/json']['schema'] ?? null;

                        if (!is_array($schema)) {
                            continue;
                        }

                        $parameters = $this->mergeParameters(
                            $parameters,
                            $this->convertSchemaPropertiesToQueryParameters($schema)
                        );
                    } catch (\Exception $e) {
                        // Silently skip FormRequests that can't be parsed
                    }
                }
            }

            return $this->mergeParameters($parameters);
        } catch (\Exception $e) {
            // Skip on error
        }

        return [];
    }

    /**
     * Extract request body from FormRequest usage
     */
    protected function extractRequestBody(string $controller, string $method, ?string $httpMethod = null): ?array
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);

            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType() instanceof \ReflectionNamedType
                    ? $param->getType()->getName()
                    : null;

                if (!$type) {
                    continue;
                }

                // Check if it's a FormRequest
                if (class_exists($type) && is_subclass_of($type, FormRequest::class)) {
                    try {
                        $requestBody = $this->formRequestParser->parse($type);
                        if (empty($requestBody)) {
                            return null;
                        }

                        if ($httpMethod === 'get') {
                            return null;
                        }

                        return $requestBody;
                    } catch (\Exception $e) {
                        // Silently skip FormRequests that can't be parsed
                        return null;
                    }
                }
            }
        } catch (\Exception $e) {
            // Skip on error
        }

        return null;
    }

    /**
     * Convert a parsed request schema into query parameters.
     */
    protected function convertSchemaPropertiesToQueryParameters(array $schema, string $prefix = ''): array
    {
        $parameters = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $propertySchema) {
            if (!is_array($propertySchema)) {
                continue;
            }

            $parameterName = $prefix === '' ? $name : "{$prefix}.{$name}";
            $type = $propertySchema['type'] ?? null;

            if ($type === 'object') {
                $parameters = array_merge(
                    $parameters,
                    $this->convertSchemaPropertiesToQueryParameters($propertySchema, $parameterName)
                );
                continue;
            }

            $parameter = [
                'name' => $parameterName,
                'in' => 'query',
                'required' => in_array($name, $required, true),
                'schema' => $propertySchema,
            ];

            if (isset($propertySchema['description'])) {
                $parameter['description'] = $propertySchema['description'];
            }

            if (array_key_exists('example', $propertySchema)) {
                $parameter['example'] = $propertySchema['example'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Merge parameter definitions while preserving richer metadata.
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

                $existing = $merged[$key];

                if (!isset($existing['description']) && isset($parameter['description'])) {
                    $existing['description'] = $parameter['description'];
                }

                if (!array_key_exists('example', $existing) && array_key_exists('example', $parameter)) {
                    $existing['example'] = $parameter['example'];
                }

                if (isset($existing['schema']) && isset($parameter['schema']) && is_array($existing['schema']) && is_array($parameter['schema'])) {
                    $existing['schema'] = array_replace_recursive($existing['schema'], $parameter['schema']);
                } elseif (!isset($existing['schema']) && isset($parameter['schema'])) {
                    $existing['schema'] = $parameter['schema'];
                }

                $existing['required'] = ($existing['required'] ?? false) || ($parameter['required'] ?? false);

                $merged[$key] = $existing;
            }
        }

        return array_values($merged);
    }

    /**
     * Resolve a possibly short filter class name to a fully qualified class.
     */
    protected function resolveFilterClass(string $filterClass): ?string
    {
        if (str_contains($filterClass, '\\')) {
            return $filterClass;
        }

        $namespaces = [
            'App\\Http\\Filters\\',
            'App\\Http\\Filters\\Api\\',
            'App\\Http\\Filters\\Mgmt\\',
        ];

        foreach ($namespaces as $ns) {
            if (class_exists($ns . $filterClass)) {
                return $ns . $filterClass;
            }
        }

        return null;
    }

    /**
     * Extract response schema from return type
     */
    protected function extractResponses(string $controller, string $method): array
    {
        $responses = [];

        try {
            $reflection = new ReflectionMethod($controller, $method);
            $returnType = $reflection->getReturnType();

            if (!$returnType) {
                return $responses;
            }

            // Handle union types (PHP 8.0+)
            if ($returnType instanceof \ReflectionUnionType) {
                // Get the first type from the union
                $types = $returnType->getTypes();
                if (empty($types)) {
                    return $responses;
                }
                $returnTypeName = $types[0] instanceof \ReflectionNamedType
                    ? $types[0]->getName()
                    : null;
            } else {
                $returnTypeName = $returnType instanceof \ReflectionNamedType
                    ? $returnType->getName()
                    : null;
            }

            // Check for ResourceCollection
            if (is_string($returnTypeName) && class_exists($returnTypeName) && is_subclass_of($returnTypeName, ResourceCollection::class)) {
                $docBlock = $reflection->getDocComment() ?: '';

                // Prefer explicit method-level @response annotations for concrete collection schemas
                $annotatedCollectionClass = $this->extractCollectionClassFromPhpDocAnnotation($docBlock);

                // Try to detect the collected resource type
                $collectedResource = $this->extractCollectedResourceFromMethod($controller, $method, $annotatedCollectionClass ?? $returnTypeName);

                if ($annotatedCollectionClass) {
                    $schema = $this->resourceParser->parse($annotatedCollectionClass);
                } else {
                    // Parse the concrete collection schema first
                    $schema = $this->resourceParser->parse($returnTypeName);
                }

                // Fallback to generic paginated collection parsing if necessary
                if (($schema['type'] ?? null) !== 'object') {
                    $schema = $this->parseResourceCollectionWithDetection($returnTypeName, $collectedResource);
                }

                // Store the resource class for schema reference on concrete collection item arrays
                if ($collectedResource && isset($schema['properties']['results']['items'])) {
                    $schema['properties']['results']['items']['x-resource-class'] = $collectedResource;
                }

                if ($collectedResource && isset($schema['properties']['data']['items'])) {
                    $schema['properties']['data']['items']['x-resource-class'] = $collectedResource;
                }

                $responses['200'] = [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => $schema,
                        ],
                    ],
                ];
            }
            // Check for JsonResource
            elseif (is_string($returnTypeName) && class_exists($returnTypeName) && is_subclass_of($returnTypeName, JsonResource::class)) {
                $schema = $this->resourceParser->parse($returnTypeName);

                // Store the resource class for schema reference
                $schema['x-resource-class'] = $returnTypeName;

                $responses['200'] = [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => $schema,
                        ],
                    ],
                ];
            }
            // Check for JsonResponse (usually for delete/destroy)
            elseif ($returnTypeName === JsonResponse::class && str_ends_with($method, 'destroy')) {
                $responses['204'] = [
                    'description' => 'Resource deleted successfully',
                ];
            }

            // Default to 200 if no specific response defined
            if (empty($responses)) {
                $responses['200'] = [
                    'description' => 'Successful response',
                ];
            }
        } catch (\Exception $e) {
            $responses['200'] = [
                'description' => 'Successful response',
            ];
        }

        return $responses;
    }

    /**
     * Parse ResourceCollection and detect collected resource type
     */
    protected function parseResourceCollectionWithDetection(string $collectionClass, ?string $collectedResource): array
    {
        // Use reflection on ResourceParser to call the protected parseResourceCollection method
        $reflection = new \ReflectionClass($this->resourceParser);
        $method = $reflection->getMethod('parseResourceCollection');
        $method->setAccessible(true);

        return $method->invoke($this->resourceParser, $collectionClass, $collectedResource);
    }

    /**
     * Extract collected resource type from controller method
     */
    protected function extractCollectedResourceFromMethod(string $controller, string $method, string $collectionClass): ?string
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);
            $filename = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if (!file_exists($filename)) {
                return null;
            }

            $lines = file($filename);
            $methodCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // First, try to extract from PHPDoc @response annotation
            $docBlock = $reflection->getDocComment();
            if ($docBlock) {
                $collectedFromDoc = $this->extractFromPhpDocAnnotation($docBlock);
                if ($collectedFromDoc) {
                    return $collectedFromDoc;
                }
            }

            // Then try to find Resource::collection() pattern
            // Pattern: SomeResource::collection() or new SomeResourceCollection()
            if (preg_match('/([A-Za-z\\\]+Resource)::collection\s*\(/', $methodCode, $matches)) {
                $resourceClass = trim($matches[1]);

                // Try to resolve the class name
                if (class_exists($resourceClass)) {
                    return $resourceClass;
                }

                // Try with App\Http\Resources namespace
                if (class_exists('App\\Http\\Resources\\' . $resourceClass)) {
                    return 'App\\Http\\Resources\\' . $resourceClass;
                }

                // Try with controller namespace
                $controllerNamespace = substr($controller, 0, strrpos($controller, '\\'));
                $appNamespace = substr($controllerNamespace, 0, strrpos($controllerNamespace, '\\'));
                $resourceNs = $appNamespace . '\\Resources';

                if (class_exists($resourceNs . '\\' . $resourceClass)) {
                    return $resourceNs . '\\' . $resourceClass;
                }

                // Try Api or Management subdirectories
                if (class_exists($resourceNs . '\\Api\\' . $resourceClass)) {
                    return $resourceNs . '\\Api\\' . $resourceClass;
                }

                if (class_exists($resourceNs . '\\Management\\' . $resourceClass)) {
                    return $resourceNs . '\\Management\\' . $resourceClass;
                }
            }

            // Try pattern: return new ResourceCollection(...)
            if (preg_match('/new\s+([A-Za-z\\\]+ResourceCollection)\s*\(/', $methodCode, $matches)) {
                $collectionClassName = trim($matches[1]);

                if (class_exists($collectionClassName)) {
                    return null; // Will be detected from collects property
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract collected resource from PHPDoc @response annotation
     * Examples:
     * - @response AnonymousResourceCollection<DataSourceResource>
     * - @response AnonymousResourceCollection<LengthAwarePaginator<DataSourceResource>>
     * - @response LengthAwarePaginator<DataSourceResource>
     * - @response Collection<DataSourceResource>
     */
    protected function extractFromPhpDocAnnotation(string $docBlock): ?string
    {
        // First, look for the most common pattern with nested generics
        // Pattern: @response ...Collection<...Paginator<ResourceClass>>
        if (preg_match('/@response\s+[a-zA-Z0-9_\\\\]*Collection\s*<[^<]*[a-zA-Z]*Paginator\s*<\s*([a-zA-Z0-9_\\\\]+)\s*>\s*>/', $docBlock, $matches)) {
            return $this->resolveResourceClass(trim($matches[1]));
        }

        // Pattern: @response ...Paginator<ResourceClass>
        if (preg_match('/@response\s+[a-zA-Z0-9_\\\\]*Paginator\s*<\s*([a-zA-Z0-9_\\\\]+)\s*>/', $docBlock, $matches)) {
            return $this->resolveResourceClass(trim($matches[1]));
        }

        // Pattern: @response ...Collection<ResourceClass>
        if (preg_match('/@response\s+[a-zA-Z0-9_\\\\]*Collection\s*<\s*([a-zA-Z0-9_\\\\]+)\s*>/', $docBlock, $matches)) {
            return $this->resolveResourceClass(trim($matches[1]));
        }

        // Pattern: Extract from nested generics - look for innermost type
        if (preg_match('/@response\s+[a-zA-Z0-9_\\\\]*(?:Collection|Paginator).*<.*<\s*([a-zA-Z0-9_\\\\]+)\s*>\s*>/', $docBlock, $matches)) {
            return $this->resolveResourceClass(trim($matches[1]));
        }

        return null;
    }

    /**
     * Extract a concrete ResourceCollection class from PHPDoc @response annotation.
     * Examples:
     * - @response SearchResultCollection
     * - @response \App\Http\Resources\Api\SearchResultCollection
     */
    protected function extractCollectionClassFromPhpDocAnnotation(string $docBlock): ?string
    {
        if (preg_match('/@response\s+([A-Za-z0-9_\\\\]*ResourceCollection)\b/', $docBlock, $matches)) {
            $collectionClass = trim($matches[1]);

            if (class_exists($collectionClass)) {
                return $collectionClass;
            }

            if (class_exists('App\\Http\\Resources\\' . $collectionClass)) {
                return 'App\\Http\\Resources\\' . $collectionClass;
            }

            if (class_exists('App\\Http\\Resources\\Api\\' . $collectionClass)) {
                return 'App\\Http\\Resources\\Api\\' . $collectionClass;
            }

            if (class_exists('App\\Http\\Resources\\Management\\' . $collectionClass)) {
                return 'App\\Http\\Resources\\Management\\' . $collectionClass;
            }

            if (class_exists('App\\Http\\Resources\\User\\' . $collectionClass)) {
                return 'App\\Http\\Resources\\User\\' . $collectionClass;
            }
        }

        return null;
    }

    /**
     * Resolve resource class name with namespace fallbacks
     */
    protected function resolveResourceClass(string $resourceClass): ?string
    {
        // Already fully qualified
        if (class_exists($resourceClass)) {
            return $resourceClass;
        }

        // Try with App\Http\Resources namespace
        if (class_exists('App\\Http\\Resources\\' . $resourceClass)) {
            return 'App\\Http\\Resources\\' . $resourceClass;
        }

        // Try Api namespace
        if (class_exists('App\\Http\\Resources\\Api\\' . $resourceClass)) {
            return 'App\\Http\\Resources\\Api\\' . $resourceClass;
        }

        // Try Management namespace
        if (class_exists('App\\Http\\Resources\\Management\\' . $resourceClass)) {
            return 'App\\Http\\Resources\\Management\\' . $resourceClass;
        }

        // Try User namespace
        if (class_exists('App\\Http\\Resources\\User\\' . $resourceClass)) {
            return 'App\\Http\\Resources\\User\\' . $resourceClass;
        }

        return null;
    }

    /**
     * Generate summary from method name and route
     */
    protected function generateSummary(Route $route): string
    {
        $uri = $route->uri();
        $methods = collect($route->methods())
            ->filter(fn($m) => !in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn($m) => strtoupper($m))
            ->first();

        // Convert snake_case to Title Case
        $parts = explode('/', trim($uri, '/'));
        $lastPart = end($parts);
        $resource = str_replace('_', ' ', $lastPart);

        $summaries = [
            'GET' => "Get {$resource}",
            'POST' => "Create {$resource}",
            'PUT' => "Update {$resource}",
            'PATCH' => "Update {$resource}",
            'DELETE' => "Delete {$resource}",
        ];

        return $summaries[$methods] ?? "API endpoint: {$methods} {$uri}";
    }

    /**
     * Generate description
     */
    protected function generateDescription(string $controller, string $method): string
    {
        return "Operation: {$method}()";
    }

    /**
     * Extract tags from controller namespace
     */
    protected function extractTags(Route $route): array
    {
        $uri = $route->uri();
        $parts = explode('/', trim($uri, '/'));

        // Skip version prefix
        if (count($parts) > 1) {
            $resource = $parts[1];
            // Convert kebab-case to Title Case
            $tag = str_replace('-', ' ', $resource);
            $tag = ucwords($tag);

            return [$tag];
        }

        return [];
    }

    /**
     * Extract prefix from URI
     */
    protected function extractPrefix(string $uri): string
    {
        $parts = explode('/', trim($uri, '/'));
        if (count($parts) >= 2) {
            return "{$parts[0]}/{$parts[1]}";
        }

        return $uri;
    }
}
