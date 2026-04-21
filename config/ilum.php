<?php

return [
    'default_format' => env('IMAGE_DEFAULT_FORMAT', 'webp'),

    'base_url' => env('IMAGE_BASE_URL', 'https://api.b10cks.com/ilum'),

    'driver' => env('IMAGE_DRIVER', 'vips'),

    'drivers' => [
        'vips' => [
            'class' => \App\Services\Image\Drivers\VipsDriver::class,
        ],
        'imagick' => [
            'class' => \App\Services\Image\Drivers\ImagickDriver::class,
        ],
    ],

    'formats' => [
        'webp' => [
            'quality' => env('IMAGE_WEBP_QUALITY', 85),
            'mime' => 'image/webp',
        ],
        'avif' => [
            'quality' => env('IMAGE_AVIF_QUALITY', 80),
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
];
