<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The source image has more pixels than `ilum.max_source_pixels` allows. The
 * file exists and is served untransformed, so this is not a 404.
 */
class ImageSourceTooLargeException extends UnprocessableEntityHttpException
{
    public function __construct(int $maxPixels)
    {
        parent::__construct(sprintf('Image exceeds the %d megapixel limit for transformations', intdiv($maxPixels, 1_000_000)));
    }
}
