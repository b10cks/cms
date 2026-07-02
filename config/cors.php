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

    /*
    | Only the public delivery API is handled by Laravel's global CORS. It
    | authenticates with a bearer token (no cookies) and is consumed from
    | arbitrary, unknowable tenant origins, so `*` without credentials is both
    | necessary and safe here.
    */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

    /*
    |--------------------------------------------------------------------------
    | Management CORS (App\Http\Middleware\HandleManagementCors)
    |--------------------------------------------------------------------------
    |
    | The management API (`mgmt/*`, `auth/v1/*`) authenticates with stateful
    | Sanctum cookies. Credentialed cross-origin requests must therefore be
    | restricted to an explicit allowlist — never `*` — otherwise any site could
    | ride a logged-in admin's session. Unlike tenant content origins, the
    | b10cks app frontend origin(s) are known. Configure them via
    | MGMT_ALLOWED_ORIGINS (comma-separated); defaults to the app's own URLs.
    |
    */
    'management_paths' => ['mgmt/*', 'auth/v1/*'],

    'management_origins' => array_values(array_unique(array_filter(
        array_map('trim', explode(',', (string) env('MGMT_ALLOWED_ORIGINS', implode(',', array_filter([
            env('APP_FRONTEND_URL'),
            env('APP_URL'),
        ])))))
    ))),
];
