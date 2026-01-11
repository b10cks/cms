<?php

return [
    'output' => [
        'directory' => public_path('docs'),
        'per_prefix' => true, // Generate separate file per prefix
    ],

    'info' => [
        'title' => env('API_DOCS_TITLE', config('app.name') . ' API'),
        'description' => 'Auto-generated API documentation',
        'version' => '1.0.0',
        'contact' => [
            'name' => 'API Support',
            'url' => config('app.url'),
        ],
    ],

    'routes' => [
        'prefixes' => [
            'api/v1' => [
                'security' => ['tokenAuth'],
                'title' => 'Data API v1',
                'description' => 'Public data API for accessing content, blocks, and data sources',
            ],
            'mgmt/v1' => [
                'security' => ['bearerAuth'],
                'title' => 'Management API v1',
                'description' => 'Protected management API for administering spaces, teams, and content',
            ],
            'auth/v1' => [
                'security' => [],
                'title' => 'Authentication API v1',
                'description' => 'Authentication endpoints for issuing and managing tokens',
            ],
        ],
    ],

    'security_schemes' => [
        'tokenAuth' => [
            'type' => 'apiKey',
            'in' => 'query',
            'name' => 'token',
            'description' => 'API token passed as query parameter',
        ],
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'JWT token passed in Authorization header',
        ],
    ],

    // Resource class directories for schema discovery
    'resource_directories' => [
        app_path('Http/Resources/Api'),
        app_path('Http/Resources/Management'),
        app_path('Http/Resources/User'),
    ],

    // Default response codes and descriptions
    'responses' => [
        '200' => 'Successful response',
        '201' => 'Resource created successfully',
        '204' => 'Resource deleted successfully',
        '400' => 'Bad request - validation or request error',
        '401' => 'Unauthorized - authentication required',
        '403' => 'Forbidden - insufficient permissions',
        '404' => 'Resource not found',
        '422' => 'Unprocessable entity - validation error',
        '500' => 'Internal server error',
    ],

    // Pagination configuration
    'pagination' => [
        'per_page_default' => 20,
        'per_page_max' => 1000,
    ],
];
