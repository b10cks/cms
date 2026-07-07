<?php

namespace App\Services\Image\Drivers;

use App\Contracts\Image\ImageDriverInterface;
use App\Contracts\Image\ImageInterface;
use App\Services\Image\Images\VipsImage;
use Jcupitt\Vips\Image as VipsImageLib;

class VipsDriver implements ImageDriverInterface
{
    /**
     * Load an image from a file path. Single-page images are auto-rotated
     * per their EXIF orientation; multi-page (animated) loads are not, as
     * autorot would treat the joined frame strip as one image.
     */
    public function loadFromFile(string $path): ImageInterface
    {
        $vipsImage = $this->shouldLoadAllPages($path)
            ? VipsImageLib::newFromFile($path, ['n' => -1])
            : VipsImageLib::newFromFile($path)->autorot();

        return new VipsImage($vipsImage);
    }

    /**
     * Load an image from a buffer
     */
    public function loadFromBuffer($buffer): ImageInterface
    {
        $vipsImage = $this->shouldLoadAllPagesFromBuffer($buffer)
            ? VipsImageLib::newFromBuffer($buffer, '', ['n' => -1])
            : VipsImageLib::newFromBuffer($buffer)->autorot();

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
     * Check if the driver is available. php-vips 2.x talks to libvips via
     * FFI, so extension_loaded('vips') is false even on working installs —
     * the only reliable check is to actually touch libvips once.
     */
    public function isAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            try {
                VipsImageLib::black(1, 1);
                $available = true;
            } catch (\Throwable) {
                $available = false;
            }
        }

        return $available;
    }

    /**
     * Get supported output formats
     */
    public function getSupportedFormats(): array
    {
        return ['webp', 'avif', 'jpg', 'png', 'gif'];
    }

    private function shouldLoadAllPages(string $path): bool
    {
        return \in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), ['gif', 'webp'], true);
    }

    private function shouldLoadAllPagesFromBuffer(string $buffer): bool
    {
        return str_starts_with($buffer, 'GIF87a')
            || str_starts_with($buffer, 'GIF89a')
            || (
                str_starts_with($buffer, 'RIFF')
                && substr($buffer, 8, 4) === 'WEBP'
            );
    }
}
