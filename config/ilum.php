<?php

use App\Services\Image\Drivers\ImagickDriver;
use App\Services\Image\Drivers\VipsDriver;

return [
    'default_format' => env('IMAGE_DEFAULT_FORMAT', 'webp'),

    'base_url' => env('IMAGE_BASE_URL', 'https://api.b10cks.com/ilum'),

    'driver' => env('IMAGE_DRIVER', 'vips'),

    // Reject source images whose pixel count (width × height) exceeds this cap
    // before decoding, to guard against decompression bombs. 0 disables it.
    'max_source_pixels' => (int) env('IMAGE_MAX_SOURCE_PIXELS', 100_000_000),

    'drivers' => [
        'vips' => [
            'class' => VipsDriver::class,
        ],
        'imagick' => [
            'class' => ImagickDriver::class,
        ],
    ],

    'formats' => [
        'webp' => [
            'quality' => env('IMAGE_WEBP_QUALITY', 85),
            'mime' => 'image/webp',
        ],
        'avif' => [
            'quality' => env('IMAGE_AVIF_QUALITY', 85),
            'mime' => 'image/avif',
        ],
        'jpg' => [
            'quality' => env('IMAGE_JPG_QUALITY', 85),
            'mime' => 'image/jpeg',
        ],
        'png' => [
            'quality' => env('IMAGE_PNG_QUALITY', 90),
            'mime' => 'image/png',
        ],
        'gif' => [
            'mime' => 'image/gif',
        ],
    ],

    'cache' => [
        'duration' => env('IMAGE_CACHE_DURATION', 31536000),
        'immutable' => env('IMAGE_CACHE_IMMUTABLE', true),
    ],

    'max_dimensions' => [
        'width' => env('IMAGE_MAX_WIDTH', 5000),
        'height' => env('IMAGE_MAX_HEIGHT', 5000),
    ],

    // Requests per minute per IP against the public transformation endpoint.
    'rate_limit' => env('IMAGE_RATE_LIMIT', 600),
];
