<?php

namespace App\Services\Image\Drivers;

use App\Contracts\Image\ImageDriverInterface;
use App\Contracts\Image\ImageInterface;
use App\Services\Image\Images\VipsImage;
use Jcupitt\Vips\Image as VipsImageLib;

class VipsDriver implements ImageDriverInterface
{
    /**
     * Load an image from a file path
     */
    public function loadFromFile(string $path): ImageInterface
    {
        $vipsImage = VipsImageLib::newFromFile($path);
        return new VipsImage($vipsImage);
    }

    /**
     * Load an image from a buffer
     */
    public function loadFromBuffer(string $buffer): ImageInterface
    {
        $vipsImage = VipsImageLib::newFromBuffer($buffer);
        return new VipsImage($vipsImage);
    }

    /**
     * Get the driver name
     */
    public function getName(): string
    {
        return 'vips';
    }

    /**
     * Check if the driver is available
     */
    public function isAvailable(): bool
    {
        return extension_loaded('vips');
    }

    /**
     * Get supported output formats
     */
    public function getSupportedFormats(): array
    {
        return ['webp', 'avif', 'jpg', 'png', 'gif'];
    }
}

