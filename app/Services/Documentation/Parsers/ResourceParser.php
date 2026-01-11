<?php

namespace App\Services\Documentation\Parsers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;

class ResourceParser
{
    protected array $parsedResources = [];

    /**
     * Parse a JsonResource class to extract response schema
     */
    public function parse(string $resourceClass): array
    {
        // Prevent circular parsing
        if (isset($this->parsedResources[$resourceClass])) {
            return ['$ref' => "#/components/schemas/{$this->getSchemaName($resourceClass)}"];
        }

        if (!class_exists($resourceClass)) {
            return ['type' => 'object'];
        }

        // Check if it's a ResourceCollection
        if (is_subclass_of($resourceClass, ResourceCollection::class)) {
            return $this->parseResourceCollection($resourceClass);
        }

        // Check if it's a JsonResource
        if (!is_subclass_of($resourceClass, JsonResource::class)) {
            return ['type' => 'object'];
        }

        try {
            $this->parsedResources[$resourceClass] = true;

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

            // Find toArray method
            $visitor = new ToArrayVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $returnArray = $visitor->getReturnArray();

            if (empty($returnArray)) {
                return ['type' => 'object'];
            }

            return $this->buildSchemaFromArray($returnArray);
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

        return $this->buildPaginationSchema($itemSchema);
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
     * Build pagination schema with item schema
     */
    protected function buildPaginationSchema(array $itemSchema): array
    {
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
                            'type' => ['string', 'null'],
                            'format' => 'uri',
                            'nullable' => true,
                            'description' => 'URL to the previous page',
                        ],
                        'next' => [
                            'type' => ['string', 'null'],
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
                            'type' => ['integer', 'null'],
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
                            'type' => ['integer', 'null'],
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
     * Build OpenAPI schema from extracted array structure
     */
    protected function buildSchemaFromArray(array $structure): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($structure as $key => $value) {
            $schema['properties'][$key] = $this->inferPropertyType($key, $value);
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
            return ['type' => 'null'];
        }

        // Handle direct values with method calls
        if (is_string($value)) {
            // Date/time formatting
            if (str_contains($value, 'toIso8601String')) {
                return ['type' => 'string', 'format' => 'date-time'];
            }

            // Route key (UUID/ID)
            if (str_contains($value, 'getRouteKey')) {
                return ['type' => 'string', 'format' => 'uuid'];
            }

            // whenLoaded (optional relationship)
            if (str_contains($value, 'whenLoaded')) {
                return [
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'null'],
                    ],
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
        $this->parsedResources = [];
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
                        $this->returnArray = $this->parseArrayNode($stmt->expr);
                        break;
                    }
                }
            }
        }
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
