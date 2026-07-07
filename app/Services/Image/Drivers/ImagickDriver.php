<?php

namespace App\Services\Image\Drivers;

use App\Contracts\Image\ImageDriverInterface;
use App\Contracts\Image\ImageInterface;
use App\Services\Image\Images\ImagickImage;
use Imagick;

class ImagickDriver implements ImageDriverInterface
{
    /**
     * Load an image from a file path
     */
    public function loadFromFile(string $path): ImageInterface
    {
        $imagick = new Imagick();
        $imagick->readImage($path);
        return new ImagickImage($this->autoOrient($imagick));
    }

    /**
     * Load an image from a buffer
     */
    public function loadFromBuffer($buffer): ImageInterface
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($buffer);
        return new ImagickImage($this->autoOrient($imagick));
    }

    /**
     * Rotate the image upright per its EXIF orientation tag
     */
    private function autoOrient(Imagick $imagick): Imagick
    {
        if ($imagick->getImageOrientation() !== Imagick::ORIENTATION_UNDEFINED) {
            $imagick->autoOrient();
        }

        return $imagick;
    }

    /**
     * Get the driver name
     */
    public function getName(): string
    {
        return 'imagick';
    }

    /**
     * Check if the driver is available
     */
    public function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Get supported output formats
     */
    public function getSupportedFormats(): array
    {
        return ['webp', 'jpg', 'png', 'gif']; // AVIF support varies by ImageMagick version
    }
}
