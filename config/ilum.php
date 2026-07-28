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

        // Transformed images are content-addressed by their URL, so they can
        // safely be immutable.
        'immutable' => env('IMAGE_CACHE_IMMUTABLE', true),

        // Untransformed files are served under a path that only *usually*
        // changes when the file does, so they stay revalidatable via ETag.
        'passthrough_immutable' => env('IMAGE_CACHE_PASSTHROUGH_IMMUTABLE', false),

        // A poster URL is stable across poster changes unless the caller pins
        // a version, so unpinned poster requests get a short TTL.
        'poster_duration' => env('IMAGE_CACHE_POSTER_DURATION', 3600),
    ],

    // Requests per minute per IP for the (unauthenticated) delivery routes.
    // A content-heavy page pulls many assets at once, so this is generous.
    'rate_limit' => (int) env('IMAGE_RATE_LIMIT', 600),

    'stream' => [
        // Bytes copied from storage to the client per iteration when streaming
        // a (possibly ranged) file.
        'chunk_size' => (int) env('IMAGE_STREAM_CHUNK_SIZE', 1048576),

        // Ceiling on a single transfer. Long enough for a large file over a
        // slow connection, short enough that a stalled client eventually
        // releases its worker. 0 disables the limit.
        'max_seconds' => (int) env('IMAGE_STREAM_MAX_SECONDS', 900),
    ],

    'max_dimensions' => [
        'width' => env('IMAGE_MAX_WIDTH', 5000),
        'height' => env('IMAGE_MAX_HEIGHT', 5000),
    ],
];
