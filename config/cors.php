<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'mgmt/*', 'auth/v1/*'],

    'allowed_methods' => ['*'],

    /*
    | The management API (`mgmt/*`, `auth/v1/*`) authenticates with stateful
    | Sanctum cookies, so credentialed cross-origin requests must be restricted
    | to an explicit allowlist — never `*`, which combined with credentials
    | would let any site ride a logged-in admin's session. Configure the trusted
    | origins via CORS_ALLOWED_ORIGINS (comma-separated); defaults to the app's
    | own frontend/app URLs.
    */
    'allowed_origins' => array_values(array_unique(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', [
            env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost')),
            env('APP_URL', 'http://localhost'),
        ]))))
    ))),

    'allowed_origins_patterns' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', '')))
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
