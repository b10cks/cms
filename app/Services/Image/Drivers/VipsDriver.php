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
        $vipsImage = $this->shouldLoadAllPages($path)
            ? VipsImageLib::newFromFile($path, ['n' => -1])
            : VipsImageLib::newFromFile($path);

        return new VipsImage($vipsImage);
    }

    /**
     * Load an image from a buffer
     */
    public function loadFromBuffer($buffer): ImageInterface
    {
        $vipsImage = $this->shouldLoadAllPagesFromBuffer($buffer)
            ? VipsImageLib::newFromBuffer($buffer, '', ['n' => -1])
            : VipsImageLib::newFromBuffer($buffer);

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
