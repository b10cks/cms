<?php

namespace App\Services\Documentation;

use App\Services\Documentation\Parsers\ResourceParser;
use Illuminate\Http\Resources\Json\JsonResource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SchemaBuilder
{
    protected array $schemas = [];

    public function __construct(
        private ResourceParser $resourceParser
    ) {}

    /**
     * Build all component schemas
     */
    public function buildAllSchemas(): array
    {
        $this->schemas = [];

        // Discover and parse all Resource classes
        $resourceClasses = $this->findAllResourceClasses();

        foreach ($resourceClasses as $resourceClass) {
            $schemaName = $this->getSchemaName($resourceClass);
            $this->schemas[$schemaName] = $this->resourceParser->parse($resourceClass);
        }

        // Add common schemas
        $this->schemas['PaginatedResponse'] = $this->buildPaginationSchema();
        $this->schemas['Error'] = $this->buildErrorSchema();
        $this->schemas['ValidationError'] = $this->buildValidationErrorSchema();

        return $this->schemas;
    }

    /**
     * Find all Resource classes in configured directories
     */
    protected function findAllResourceClasses(): array
    {
        $classes = [];
        $directories = config('docs.resource_directories', [
            app_path('Http/Resources/Api'),
            app_path('Http/Resources/Management'),
            app_path('Http/Resources/User'),
        ]);

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->fileToClassName($file->getPathname());

                if (class_exists($className) && is_subclass_of($className, JsonResource::class)) {
                    $classes[] = $className;
                }
            }
        }

        return array_unique($classes);
    }

    /**
     * Convert file path to class name
     */
    protected function fileToClassName(string $filePath): string
    {
        // Get relative path from app directory
        $appPath = app_path();
        $relativePath = str_replace($appPath, '', $filePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = trim($relativePath, '/');

        // Remove .php extension
        $relativePath = substr($relativePath, 0, -4);

        // Convert to class name
        $className = 'App\\' . str_replace('/', '\\', $relativePath);

        return $className;
    }

    /**
     * Get schema name from class
     */
    protected function getSchemaName(string $resourceClass): string
    {
        return class_basename($resourceClass);
    }

    /**
     * Build pagination wrapper schema
     */
    protected function buildPaginationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Collection of resources',
                ],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'description' => 'Link to first page',
                        ],
                        'last' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'description' => 'Link to last page',
                        ],
                        'prev' => [
                            'type' => ['string', 'null'],
                            'format' => 'uri',
                            'nullable' => true,
                            'description' => 'Link to previous page',
                        ],
                        'next' => [
                            'type' => ['string', 'null'],
                            'format' => 'uri',
                            'nullable' => true,
                            'description' => 'Link to next page',
                        ],
                    ],
                    'required' => ['first', 'last', 'prev', 'next'],
                ],
                'meta' => [
                    'type' => 'object',
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
                    'required' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
                ],
            ],
            'required' => ['data', 'links', 'meta'],
        ];
    }

    /**
     * Build error response schema
     */
    protected function buildErrorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Error message',
                ],
                'error' => [
                    'type' => 'string',
                    'description' => 'Error code or type',
                ],
                'code' => [
                    'type' => 'integer',
                    'description' => 'HTTP status code',
                ],
            ],
            'required' => ['message'],
        ];
    }

    /**
     * Build validation error schema
     */
    protected function buildValidationErrorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Error message',
                ],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Error messages for each field',
                    ],
                ],
            ],
            'required' => ['message', 'errors'],
        ];
    }

    /**
     * Get all built schemas
     */
    public function getSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Clear schemas cache
     */
    public function clear(): void
    {
        $this->schemas = [];
        $this->resourceParser->clearCache();
    }
}
