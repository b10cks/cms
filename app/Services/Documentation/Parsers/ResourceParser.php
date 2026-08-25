<?php

namespace App\Services\Documentation\Parsers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;

class ResourceParser
{
    /** Resources currently being parsed, to break reference cycles */
    protected array $parsing = [];

    /** Finished schemas, so a resource parses the same however often it is asked for */
    protected array $parsedSchemas = [];

    /**
     * Parse a JsonResource class to extract response schema
     */
    public function parse(string $resourceClass): array
    {
        // A resource reached again while it is still being parsed is a cycle
        // and becomes a $ref. One that already finished is returned in full,
        // so a schema never depends on how often it was asked for before.
        if (isset($this->parsing[$resourceClass])) {
            return ['$ref' => "#/components/schemas/{$this->getSchemaName($resourceClass)}"];
        }

        if (isset($this->parsedSchemas[$resourceClass])) {
            return $this->parsedSchemas[$resourceClass];
        }

        if (!class_exists($resourceClass)) {
            return ['type' => 'object'];
        }

        $this->parsing[$resourceClass] = true;

        try {
            // Check if it's a ResourceCollection
            if (is_subclass_of($resourceClass, ResourceCollection::class)) {
                return $this->parsedSchemas[$resourceClass] = $this->parseConcreteResourceCollection($resourceClass);
            }

            // Check if it's a JsonResource
            if (!is_subclass_of($resourceClass, JsonResource::class)) {
                return ['type' => 'object'];
            }

            return $this->parsedSchemas[$resourceClass] = $this->buildResourceSchema($resourceClass);
        } finally {
            unset($this->parsing[$resourceClass]);
        }
    }

    /**
     * Build the schema of a JsonResource from its toArray() method
     */
    protected function buildResourceSchema(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $filename = $reflection->getFileName();

            if (!file_exists($filename)) {
                return ['type' => 'object'];
            }

            $code = file_get_contents($filename);

            try {
                $parser = new \PhpParser\Parser\Php7(new \PhpParser\Lexer\Emulative());
                $ast = $parser->parse($code);
            } catch (\Exception $e) {
                return ['type' => 'object'];
            }

            $resourceMetadata = $this->extractResourceMetadata($reflection->getDocComment() ?: null);
            $parentSchema = $this->extractParentResourceSchema($reflection);

            // Find toArray method
            $visitor = new ToArrayVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $returnArray = $visitor->getReturnArray();

            if (empty($returnArray)) {
                return $parentSchema ?: ['type' => 'object'];
            }

            $schema = $this->buildSchemaFromArray($returnArray, $resourceMetadata);

            if ($parentSchema) {
                $schema = $this->mergeSchemas($parentSchema, $schema);
            }

            return $schema;
        } catch (\Exception $e) {
            return ['type' => 'object'];
        }
    }

    /**
     * Parse ResourceCollection and detect the collected resource type
     */
    protected function parseResourceCollection(string $resourceClass, ?string $collectedResourceClass = null): array
    {
        $itemSchema = ['type' => 'object'];

        // Try different methods to detect the collected resource
        if ($collectedResourceClass && class_exists($collectedResourceClass)) {
            $itemSchema = $this->parse($collectedResourceClass);
        } else {
            // Try to instantiate and get collects property
            try {
                $instance = app($resourceClass);
                if (isset($instance->collects) && class_exists($instance->collects)) {
                    $itemSchema = $this->parse($instance->collects);
                }
            } catch (\Exception $e) {
                // Try to extract from class definition
                $extracted = $this->extractCollectedResourceFromClass($resourceClass);
                if ($extracted && class_exists($extracted)) {
                    $itemSchema = $this->parse($extracted);
                }
            }
        }

        return $this->buildPaginationSchema($itemSchema, $resourceClass);
    }

    /**
     * Parse a concrete ResourceCollection class by inspecting its own toArray structure.
     */
    protected function parseConcreteResourceCollection(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $filename = $reflection->getFileName();

            if (!file_exists($filename)) {
                return $this->parseResourceCollection($resourceClass);
            }

            $code = file_get_contents($filename);

            try {
                $parser = new \PhpParser\Parser\Php7(new \PhpParser\Lexer\Emulative());
                $ast = $parser->parse($code);
            } catch (\Exception $e) {
                return $this->parseResourceCollection($resourceClass);
            }

            $resourceMetadata = $this->extractResourceMetadata($reflection->getDocComment() ?: null);
            $visitor = new ToArrayVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $returnArray = $visitor->getReturnArray();
            if (empty($returnArray)) {
                return $this->parseResourceCollection($resourceClass);
            }

            $schema = $this->buildSchemaFromArray($returnArray, $resourceMetadata);
            $collectedResourceClass = $this->extractCollectedResourceFromClass($resourceClass);

            if ($collectedResourceClass) {
                foreach (['results', 'data'] as $collectionProperty) {
                    if (isset($schema['properties'][$collectionProperty]) && is_array($schema['properties'][$collectionProperty])) {
                        $schema['properties'][$collectionProperty] = [
                            'type' => 'array',
                            'description' => $schema['properties'][$collectionProperty]['description'] ?? 'Collection of resources',
                            'items' => $this->parse($collectedResourceClass),
                        ];
                    }
                }
            }

            return $schema;
        } catch (\Exception $e) {
            return $this->parseResourceCollection($resourceClass);
        }
    }

    /**
     * Extract inherited schema from a parent JsonResource class.
     */
    protected function extractParentResourceSchema(ReflectionClass $reflection): ?array
    {
        $parentClass = $reflection->getParentClass();

        if (!$parentClass) {
            return null;
        }

        $parentClassName = $parentClass->getName();

        if (!is_subclass_of($parentClassName, JsonResource::class)) {
            return null;
        }

        return $this->parse($parentClassName);
    }

    /**
     * Merge parent and child resource schemas, with child properties overriding parent properties.
     */
    protected function mergeSchemas(array $parentSchema, array $childSchema): array
    {
        if (($parentSchema['type'] ?? null) !== 'object' || ($childSchema['type'] ?? null) !== 'object') {
            return $childSchema;
        }

        $merged = $parentSchema;
        $merged['properties'] = [
            ...($parentSchema['properties'] ?? []),
            ...($childSchema['properties'] ?? []),
        ];

        if (isset($parentSchema['required']) || isset($childSchema['required'])) {
            $merged['required'] = array_values(array_unique([
                ...($parentSchema['required'] ?? []),
                ...($childSchema['required'] ?? []),
            ]));
        }

        foreach ($childSchema as $key => $value) {
            if ($key === 'properties' || $key === 'required') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * Extract collected resource class from ResourceCollection definition
     */
    protected function extractCollectedResourceFromClass(string $resourceClass): ?string
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $filename = $reflection->getFileName();

            if (!file_exists($filename)) {
                return null;
            }

            $code = file_get_contents($filename);

            // Look for public $collects = 'ResourceClass'
            if (preg_match('/public\s+\$collects\s*=\s*([A-Za-z0-9_\\\\:]+)::class/', $code, $matches)) {
                $className = trim($matches[1], '\\');
                if (class_exists($className)) {
                    return $className;
                }

                $apiClass = 'App\\Http\\Resources\\Api\\' . $className;
                if (class_exists($apiClass)) {
                    return $apiClass;
                }

                $managementClass = 'App\\Http\\Resources\\Management\\' . $className;
                if (class_exists($managementClass)) {
                    return $managementClass;
                }

                $userClass = 'App\\Http\\Resources\\User\\' . $className;
                if (class_exists($userClass)) {
                    return $userClass;
                }

                $appClass = 'App\\Http\\Resources\\' . $className;
                if (class_exists($appClass)) {
                    return $appClass;
                }
            }

            if (preg_match('/public\s+\$collects\s*=\s*[\'"]([^\'\"]+)[\'"]/', $code, $matches)) {
                $className = $matches[1];
                // Try to resolve the class name
                if (class_exists($className)) {
                    return $className;
                }
                // Try with App\Http\Resources namespace
                $appClass = 'App\\Http\\Resources\\' . $className;
                if (class_exists($appClass)) {
                    return $appClass;
                }
            }

            // Look for collects method or property setter
            if (preg_match('/protected\s+function\s+__construct|public\s+function\s+__construct/i', $code)) {
                if (preg_match('/\$this->collects\s*=\s*[\'"]?([A-Za-z\\\]+)[\'"]?/', $code, $matches)) {
                    $className = trim($matches[1], '\'"');
                    if (class_exists($className)) {
                        return $className;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a resource class name with namespace fallbacks.
     */
    protected function resolveResourceClassName(string $resourceClass): ?string
    {
        if (class_exists($resourceClass)) {
            return $resourceClass;
        }

        foreach ([
            'App\\Http\\Resources\\Api\\',
            'App\\Http\\Resources\\Management\\',
            'App\\Http\\Resources\\User\\',
            'App\\Http\\Resources\\',
        ] as $namespace) {
            if (class_exists($namespace . $resourceClass)) {
                return $namespace . $resourceClass;
            }
        }

        return null;
    }

    /**
     * Build pagination schema with item schema
     */
    protected function buildPaginationSchema(array $itemSchema, ?string $resourceClass = null): array
    {
        $wrap = $this->wrapperKey($resourceClass);

        // A collection that renames its wrapper is answering with one whole
        // thing rather than a page of records, so it gets neither the `data`
        // key nor the pagination envelope around it.
        if ($wrap !== 'data') {
            return [
                'type' => 'object',
                'properties' => [
                    $wrap => [
                        'type' => 'array',
                        'description' => 'Collection of resources',
                        'items' => $itemSchema,
                    ],
                ],
            ];
        }

        return [
            'type' => 'object',
            'description' => 'Paginated resource collection',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'description' => 'Collection of resources',
                    'items' => $itemSchema,
                ],
                'links' => [
                    'type' => 'object',
                    'description' => 'Pagination links',
                    'properties' => [
                        'first' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'description' => 'URL to the first page',
                        ],
                        'last' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'description' => 'URL to the last page',
                        ],
                        'prev' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'nullable' => true,
                            'description' => 'URL to the previous page',
                        ],
                        'next' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'nullable' => true,
                            'description' => 'URL to the next page',
                        ],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'description' => 'Pagination metadata',
                    'properties' => [
                        'current_page' => [
                            'type' => 'integer',
                            'description' => 'Current page number',
                        ],
                        'from' => [
                            'type' => 'integer',
                            'nullable' => true,
                            'description' => 'Index of first item on current page',
                        ],
                        'last_page' => [
                            'type' => 'integer',
                            'description' => 'Total number of pages',
                        ],
                        'per_page' => [
                            'type' => 'integer',
                            'description' => 'Number of items per page',
                        ],
                        'to' => [
                            'type' => 'integer',
                            'nullable' => true,
                            'description' => 'Index of last item on current page',
                        ],
                        'total' => [
                            'type' => 'integer',
                            'description' => 'Total number of items',
                        ],
                    ],
                ],
            ],
            'required' => ['data', 'links', 'meta'],
        ];
    }

    /**
     * The key a resource collection wraps its items in, from Laravel's `$wrap`.
     */
    protected function wrapperKey(?string $resourceClass): string
    {
        if ($resourceClass === null || !class_exists($resourceClass)) {
            return 'data';
        }

        return is_string($resourceClass::$wrap ?? null) ? $resourceClass::$wrap : 'data';
    }

    /**
     * Build OpenAPI schema from extracted array structure
     */
    protected function buildSchemaFromArray(array $structure, array $metadata = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($structure as $key => $value) {
            $propertySchema = $this->inferPropertyType($key, $value);
            $schema['properties'][$key] = $this->applyPropertyMetadata($propertySchema, $metadata[$key] ?? []);
        }

        return $schema;
    }

    /**
     * Infer OpenAPI type for a property
     */
    protected function inferPropertyType(string $key, $value): array
    {
        // Handle null
        if ($value === null) {
            return ['nullable' => true];
        }

        // Handle direct values with method calls
        if (is_string($value)) {
            // Dynamic content payloads
            if (
                $key === 'content'
                || str_contains($value, 'getTransformedContent(')
                || str_contains($value, 'new \stdClass')
                || str_contains($value, 'new stdClass')
            ) {
                return [
                    'type' => 'object',
                    'additionalProperties' => true,
                ];
            }

            // Resource arrays / collections
            if (
                str_contains($value, '::collection(')
                || str_contains($value, '->resolve(')
                || str_contains($value, '->all()')
                || str_contains($value, '->toArray(')
            ) {
                if (preg_match('/([A-Za-z0-9_\\\\]+Resource)::collection\s*\(/', $value, $matches)) {
                    $resourceClass = $this->resolveResourceClassName($matches[1]);
                    if ($resourceClass) {
                        return [
                            'type' => 'array',
                            'items' => $this->parse($resourceClass),
                        ];
                    }
                }

                if (preg_match('/([A-Za-z0-9_\\\\]+Resource)::collection/', $value, $matches)) {
                    $resourceClass = $this->resolveResourceClassName($matches[1]);
                    if ($resourceClass) {
                        return [
                            'type' => 'array',
                            'items' => $this->parse($resourceClass),
                        ];
                    }
                }

                return [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ];
            }

            // Date/time formatting
            if (str_contains($value, 'toIso8601String')) {
                return ['type' => 'string', 'format' => 'date-time', 'nullable' => true];
            }

            // Route key (UUID/ID)
            if (str_contains($value, 'getRouteKey')) {
                return ['type' => 'string', 'format' => 'uuid'];
            }

            // whenLoaded (optional relationship)
            if (str_contains($value, 'whenLoaded')) {
                return [
                    'type' => 'object',
                    'nullable' => true,
                ];
            }

            // whenCounted (optional count)
            if (str_contains($value, 'whenCounted')) {
                return ['type' => 'integer'];
            }

            // StdClass (empty object)
            if (str_contains($value, 'StdClass')) {
                return ['type' => 'object'];
            }

            // Default string
            return ['type' => 'string'];
        }

        // Handle nested arrays (objects)
        if (is_array($value)) {
            return $this->buildSchemaFromArray($value);
        }

        // Handle booleans
        if (is_bool($value)) {
            return ['type' => 'boolean'];
        }

        // Handle integers
        if (is_int($value)) {
            return ['type' => 'integer'];
        }

        // Default fallback
        return ['type' => 'string'];
    }

    /**
     * Extract annotation-driven metadata from the resource docblock.
     *
     * Supported annotations:
     * @resourceProperty field description text
     * @resourceProperty field type=string description text
     * @resourceProperty field format=date-time description text
     * @resourceProperty field example=value description text
     * @resourceProperty field additionalProperties=true description text
     * @resourceProperty field items=SomeResource description text
     */
    protected function extractResourceMetadata(?string $docComment): array
    {
        if ($docComment === null || $docComment === '') {
            return [];
        }

        $metadata = [];
        $lines = preg_split('/\R/', $docComment) ?: [];

        foreach ($lines as $line) {
            if (!preg_match('/@resourceProperty\s+([A-Za-z0-9_\.]+)(?:\s+(.*))?$/', trim($line, " \t\n\r\0\x0B*"), $matches)) {
                continue;
            }

            $field = $matches[1];
            $tail = trim($matches[2] ?? '');

            $entry = $metadata[$field] ?? [];

            if (preg_match_all('/(\w+)=("([^"]*)"|\'([^\']*)\'|(\S+))/', $tail, $attributeMatches, PREG_SET_ORDER)) {
                foreach ($attributeMatches as $attributeMatch) {
                    $entry[$attributeMatch[1]] = $attributeMatch[3] ?: $attributeMatch[4] ?: $attributeMatch[5] ?: null;
                }

                $tail = trim(preg_replace('/(\w+)=("([^"]*)"|\'([^\']*)\'|(\S+))/', '', $tail) ?? '');
            }

            if ($tail !== '') {
                $entry['description'] = $tail;
            }

            $metadata[$field] = $entry;
        }

        return $metadata;
    }

    /**
     * Apply extracted metadata to a generated property schema.
     */
    protected function applyPropertyMetadata(array $schema, array $metadata): array
    {
        if (isset($metadata['description']) && $metadata['description'] !== '') {
            $schema['description'] = $metadata['description'];
        }

        if (isset($metadata['type']) && $metadata['type'] !== '') {
            $schema['type'] = $metadata['type'];
        }

        if (isset($metadata['format']) && $metadata['format'] !== '') {
            if ($metadata['format'] === 'integer') {
                $schema['type'] = 'integer';
                unset($schema['format']);
            } elseif ($metadata['format'] === 'number') {
                $schema['type'] = 'number';
                unset($schema['format']);
            } elseif ($metadata['format'] === 'boolean') {
                $schema['type'] = 'boolean';
                unset($schema['format']);
            } elseif ($metadata['format'] === 'array') {
                $schema['type'] = 'array';
                unset($schema['format']);
            } elseif ($metadata['format'] === 'object') {
                $schema['type'] = 'object';
                unset($schema['format']);
            } elseif ($metadata['format'] !== 'string') {
                $schema['format'] = $metadata['format'];
            }
        }

        if (array_key_exists('example', $metadata)) {
            $schema['example'] = $metadata['example'];
        }

        if (array_key_exists('nullable', $metadata)) {
            $schema['nullable'] = filter_var($metadata['nullable'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('additionalProperties', $metadata)) {
            $additionalProperties = $metadata['additionalProperties'];

            if (is_string($additionalProperties) && in_array(strtolower($additionalProperties), ['true', 'false'], true)) {
                $schema['additionalProperties'] = filter_var($additionalProperties, FILTER_VALIDATE_BOOLEAN);
            } else {
                $schema['additionalProperties'] = $additionalProperties;
            }
        }

        if (isset($metadata['items']) && $metadata['items'] !== '') {
            $schema['type'] = 'array';

            $resourceClass = $this->resolveResourceClassName($metadata['items']);
            $schema['items'] = $resourceClass
                ? $this->parse($resourceClass)
                : ['type' => 'object'];
        }

        return $schema;
    }

    /**
     * Get schema name from class name
     */
    protected function getSchemaName(string $resourceClass): string
    {
        return class_basename($resourceClass);
    }

    /**
     * Clear parsed resources cache
     */
    public function clearCache(): void
    {
        $this->parsing = [];
        $this->parsedSchemas = [];
    }
}

/**
 * AST Visitor to extract toArray() method return value
 */
class ToArrayVisitor extends NodeVisitorAbstract
{
    protected ?array $returnArray = null;

    public function enterNode(Node $node)
    {
        // Look for toArray method
        if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'toArray') {
            // Find return statement
            if (!empty($node->stmts)) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Return_) {
                        $this->returnArray = $this->parseExpression($stmt->expr);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Parse expression node to PHP array structure
     */
    protected function parseExpression($node): array
    {
        if ($node instanceof Node\Expr\Array_) {
            return $this->parseArrayNode($node);
        }

        if ($node instanceof Node\Expr\BinaryOp\Array_) {
            return array_merge(
                $this->parseExpression($node->left),
                $this->parseExpression($node->right),
            );
        }

        return [];
    }

    /**
     * Parse array node to PHP array structure
     */
    protected function parseArrayNode($node): array
    {
        if ($node instanceof Node\Expr\Array_) {
            $result = [];

            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                if ($item->unpack) {
                    $result = array_merge($result, $this->parseExpression($item->value));
                    continue;
                }

                $key = $this->getNodeValue($item->key);
                $value = $this->getNodeValue($item->value);

                if ($key !== null) {
                    $result[$key] = $value;
                }
            }

            return $result;
        }

        return [];
    }

    /**
     * Convert AST node to PHP value
     */
    protected function getNodeValue($node)
    {
        if ($node === null) {
            return null;
        }

        // String literals
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        // Numbers
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\Float_) {
            return $node->value;
        }

        // Booleans
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();
            if ($name === 'true') {
                return true;
            }
            if ($name === 'false') {
                return false;
            }
            if ($name === 'null') {
                return null;
            }
        }

        // Arrays
        if ($node instanceof Node\Expr\Array_) {
            return $this->parseArrayNode($node);
        }

        // Method calls
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->methodCallToString($node);
        }

        // Property access
        if ($node instanceof Node\Expr\PropertyFetch) {
            return $this->propertyToString($node);
        }

        // New instances
        if ($node instanceof Node\Expr\New_) {
            return $this->newToString($node);
        }

        // Ternary/conditional
        if ($node instanceof Node\Expr\Ternary) {
            return 'conditional_value';
        }

        // Function calls (for json_encode, etc)
        if ($node instanceof Node\Expr\FuncCall) {
            return 'function_call_value';
        }

        return null;
    }

    /**
     * Convert method call to string representation
     */
    protected function methodCallToString(Node\Expr\MethodCall $node): string
    {
        $methodName = $node->name->toString();

        // Handle $this->created_at->toIso8601String()
        if ($methodName === 'toIso8601String') {
            return "toIso8601String";
        }

        // Handle $this->getRouteKey()
        if ($methodName === 'getRouteKey') {
            return "getRouteKey";
        }

        // Handle whenLoaded()
        if ($methodName === 'whenLoaded') {
            return "whenLoaded";
        }

        // Handle whenCounted()
        if ($methodName === 'whenCounted') {
            return "whenCounted";
        }

        // Handle additional()
        if ($methodName === 'additional') {
            return "additional";
        }

        return "{$methodName}_value";
    }

    /**
     * Convert property access to string
     */
    protected function propertyToString(Node\Expr\PropertyFetch $node): string
    {
        $propertyName = $node->name->toString();
        return "\${$propertyName}";
    }

    /**
     * Convert new instance to string
     */
    protected function newToString(Node\Expr\New_ $node): string
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();
            if (str_contains($className, 'StdClass')) {
                return "StdClass_instance";
            }
            return "new_{$className}";
        }

        return 'new_instance';
    }

    public function getReturnArray(): ?array
    {
        return $this->returnArray;
    }
}
