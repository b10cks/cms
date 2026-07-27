<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    /*
     |--------------------------------------------------------------------------
     | Application Name
     |--------------------------------------------------------------------------
     |
     | This value is the name of your application, which will be used when the
     | framework needs to place the application's name in a notification or
     | other UI elements where an application name needs to be displayed.
     |
     */

    'name' => env('APP_NAME', 'Laravel'),

    'version' => env('APP_VERSION', '15d82c34b7a9f0e6'),

    // Base URL of the public documentation. Self-hosted instances that run their
    // own docs can repoint this; paths are appended (e.g. `/guides/nuxt`).
    'docs_url' => rtrim(env('APP_DOCS_URL', 'https://www.b10cks.com/docs'), '/'),

    'community_url' => env('APP_COMMUNITY_URL', 'https://discord.gg/zAz6sBDpHT'),

    'sidebar_menu' => json_decode(
        env('APP_SIDEBAR_MENU', json_encode([
            [
                'label' => 'Documentation',
                'icon' => 'lucide:book-open-check',
                'href' => 'https://www.b10cks.com/docs',
            ],
            [
                'label' => "What's new",
                'icon' => 'lucide:sparkles',
                'href' => 'https://github.com/b10cks/cms/blob/main/CHANGELOG.md',
            ],
            [
                'label' => 'Community',
                'icon' => 'lucide:users',
                'href' => 'https://discord.gg/zAz6sBDpHT',
            ],
            [
                'label' => 'Report a problem',
                'icon' => 'lucide:lightbulb',
                'href' => 'https://github.com/b10cks/cms/issues/new/choose',
            ],
        ])),
        true,
    ) ?: [],

    /*
     |--------------------------------------------------------------------------
     | Application Environment
     |--------------------------------------------------------------------------
     |
     | This value determines the "environment" your application is currently
     | running in. This may determine how you prefer to configure various
     | services the application utilizes. Set this in your ".env" file.
     |
     */

    'env' => env('APP_ENV', 'production'),

    /*
     |--------------------------------------------------------------------------
     | Application Debug Mode
     |--------------------------------------------------------------------------
     |
     | When your application is in debug mode, detailed error messages with
     | stack traces will be shown on every error that occurs within your
     | application. If disabled, a simple generic error page is shown.
     |
     */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
     |--------------------------------------------------------------------------
     | Application URL
     |--------------------------------------------------------------------------
     |
     | This URL is used by the console to properly generate URLs when using
     | the Artisan command line tool. You should set this to the root of
     | your application so that it is used when running Artisan tasks.
     |
     */

    'url' => env('APP_URL', 'http://localhost'),

    'api_url' => env('APP_API_BASE_URL', ''),

    'frontend_url' => env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost')),

    'domain' => env('APP_DOMAIN', 'localhost'),

    // Extra Host header values accepted beyond APP_URL and its subdomains.
    // See App\Http\Middleware\TrustHosts.
    'trusted_hosts' => env('TRUSTED_HOSTS', ''),

    // Per-user/IP request cap per minute for the authenticated management API.
    'mgmt_rate_limit' => env('MGMT_RATE_LIMIT', 1000),

    'asset_url' => env('ASSET_URL'),

    /*
     |--------------------------------------------------------------------------
     | Application Timezone
     |--------------------------------------------------------------------------
     |
     | Here you may specify the default timezone for your application, which
     | will be used by the PHP date and date-time functions. The timezone
     | is set to "UTC" by default as it is suitable for most use cases.
     |
     */

    'timezone' => 'UTC',

    /*
     |--------------------------------------------------------------------------
     | Application Locale Configuration
     |--------------------------------------------------------------------------
     |
     | The application locale determines the default locale that will be used
     | by Laravel's translation / localization methods. This option can be
     | set to any locale for which you plan to have translation strings.
     |
     */

    'locale' => 'en',

    'locales' => [
        'cs',
        'da',
        'de',
        'el',
        'en',
        'es',
        'fr',
        'hr',
        'hu',
        'it',
        'ja',
        'ko',
        'nl',
        'pl',
        'ru',
        'sk',
        'sv',
        'uk',
    ],

    /*
     |--------------------------------------------------------------------------
     | Application Fallback Locale
     |--------------------------------------------------------------------------
     |
     | The fallback locale determines the locale to use when the current one
     | is not available. You may change the value to correspond to any of
     | the language folders that are provided through your application.
     |
     */

    'fallback_locale' => 'en',

    /*
     |--------------------------------------------------------------------------
     | Faker Locale
     |--------------------------------------------------------------------------
     |
     | This locale will be used by the Faker PHP library when generating fake
     | data for your database seeds. For example, this will be used to get
     | localized telephone numbers, street address information and more.
     |
     */

    'faker_locale' => 'en_US',

    /*
     |--------------------------------------------------------------------------
     | Encryption Key
     |--------------------------------------------------------------------------
     |
     | This key is utilized by Laravel's encryption services and should be set
     | to a random, 32 character string to ensure that all encrypted values
     | are secure. You should do this prior to deploying the application.
     |
     */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],

    'track_usage' => env('TRACK_TOKEN_USAGE', false),

    // Origin micro-cache TTL (seconds) for heavy delivery endpoints; disabled by default.
    'micro_cache_ttl' => (int) env('DATA_API_MICRO_CACHE_TTL', 0),

    /*
     |--------------------------------------------------------------------------
     | Maintenance Mode Driver
     |--------------------------------------------------------------------------
     |
     | These configuration options determine the driver used to determine and
     | manage Laravel's "maintenance mode" status. The "cache" driver will
     | allow maintenance mode to be controlled across multiple machines.
     |
     | Supported drivers: "file", "cache"
     |
     */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Autoloaded Service Providers
     |--------------------------------------------------------------------------
     |
     | The service providers listed here will be automatically loaded on the
     | request to your application. Feel free to add your own services to
     | this array to grant expanded functionality to your applications.
     |
     */

    'providers' => ServiceProvider::defaultProviders()
        ->merge([
            /*
             * Package Service Providers...
             */

            /*
             * Application Service Providers...
             */
            App\Providers\AppServiceProvider::class,
            App\Providers\AuthServiceProvider::class,
            App\Providers\AutomationServiceProvider::class,
            App\Providers\BroadcastServiceProvider::class,
            App\Providers\EventServiceProvider::class,
            App\Providers\RouteServiceProvider::class,
            App\Providers\ContentServiceProvider::class,
            App\Providers\StorageServiceProvider::class,
        ])
        ->toArray(),

    /*
     |--------------------------------------------------------------------------
     | Class Aliases
     |--------------------------------------------------------------------------
     |
     | This array of class aliases will be registered when this application
     | is started. However, feel free to register as many as you wish as
     | the aliases are "lazy" loaded so they don't hinder performance.
     |
     */

    'aliases' => Facade::defaultAliases()
        ->merge([
            // 'Example' => App\Facades\Example::class,
        ])
        ->toArray(),
];
