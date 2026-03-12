<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'bedrock' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
    ],

    'aws' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
    ],

    'cloudfront' => [
        'log_bucket' => env('CLOUDFRONT_LOG_BUCKET'),
        'log_prefix' => env('CLOUDFRONT_LOG_PREFIX', ''),
        'distribution_id' => env('CLOUDFRONT_DISTRIBUTION_ID'),
        'ingestion' => [
            'batch_size' => env('CLOUDFRONT_INGESTION_BATCH_SIZE', 500),
            'max_files_per_run' => env('CLOUDFRONT_INGESTION_MAX_FILES_PER_RUN', 50),
            'retry_failed_after_hours' => env('CLOUDFRONT_RETRY_FAILED_HOURS', 24),
        ],
    ],

    'posthog' => [
        'api_key' => env('POSTHOG_API_KEY'),
        'settings' => [
            'host' => env('POSTHOG_HOST', 'https://eu.i.posthog.com'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'opensearch' => [
        'host' => env('OPENSEARCH_HOST', 'http://localhost:9200'),
        'username' => env('OPENSEARCH_USERNAME'),
        'password' => env('OPENSEARCH_PASSWORD'),
        'verify_ssl' => env('OPENSEARCH_VERIFY_SSL', true),
    ],

    'lemonsqueezy' => [
        'api_key' => env('LEMONSQUEEZY_API_KEY'),
        'store_id' => env('LEMONSQUEEZY_STORE_ID'),
        'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET'),
    ],
];
