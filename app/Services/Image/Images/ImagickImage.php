<?php

namespace App\Services\Image\Images;

use App\Contracts\Image\ImageInterface;
use Imagick;
use ImagickPixel;

class ImagickImage implements ImageInterface
{
    public function __construct(
        private Imagick $imagick
    ) {
    }

    public function getWidth(): int
    {
        return $this->imagick->getImageWidth();
    }

    public function getHeight(): int
    {
        return $this->imagick->getImageHeight();
    }

    public function resize(int $width, int $height): ImageInterface
    {
        $cloned = clone $this->imagick;
        $cloned->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        return new static($cloned);
    }

    public function crop(int $x, int $y, int $width, int $height): ImageInterface
    {
        $cloned = clone $this->imagick;
        $cloned->cropImage($width, $height, $x, $y);
        return new static($cloned);
    }

    public function fit(int $width, int $height): ImageInterface
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();

        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        $cloned = clone $this->imagick;

        if ($ratioOriginal > $ratioTarget) {
            // Image is wider than target ratio
            $newHeight = $height;
            $newWidth = round($height * $ratioOriginal);
            $cloned->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

            $leftOffset = ($newWidth - $width) / 2;
            $cloned->cropImage($width, $newHeight, $leftOffset, 0);
        } else {
            // Image is taller than target ratio
            $newWidth = $width;
            $newHeight = round($width / $ratioOriginal);
            $cloned->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

            $topOffset = ($newHeight - $height) / 2;
            $cloned->cropImage($newWidth, $height, 0, $topOffset);
        }

        return new static($cloned);
    }

    public function smartFit(int $width, int $height): ImageInterface
    {
        // ImageMagick doesn't have built-in attention-based cropping
        // Fall back to regular fit operation
        return $this->fit($width, $height);
    }

    public function fitFocus(int $focusX, int $focusY, int $width, int $height): ImageInterface
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();

        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        $cloned = clone $this->imagick;

        if ($ratioOriginal > $ratioTarget) {
            $newHeight = $height;
            $newWidth = round($height * $ratioOriginal);
        } else {
            $newWidth = $width;
            $newHeight = round($width / $ratioOriginal);
        }

        $cloned->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

        // Calculate focus point in new dimensions
        $focusXPixel = round(($focusX / 100) * $originalWidth);
        $focusYPixel = round(($focusY / 100) * $originalHeight);

        $focusXPixel = round($focusXPixel * ($newWidth / $originalWidth));
        $focusYPixel = round($focusYPixel * ($newHeight / $originalHeight));

        // Calculate crop position
        $leftOffset = max(0, min($focusXPixel - ($width / 2), $newWidth - $width));
        $topOffset = max(0, min($focusYPixel - ($height / 2), $newHeight - $height));

        $cloned->cropImage($width, $height, $leftOffset, $topOffset);
        return new static($cloned);
    }

    public function cropResize(int $x, int $y, int $width, int $height, int $targetWidth, int $targetHeight): ImageInterface
    {
        $cloned = clone $this->imagick;
        $cloned->cropImage($width, $height, $x, $y);
        $cloned->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
        return new static($cloned);
    }

    public function toBuffer(string $format, array $options = []): string
    {
        $cloned = clone $this->imagick;

        // Set format
        $cloned->setImageFormat($format);

        // Apply format-specific options
        switch ($format) {
            case 'webp':
                if (isset($options['quality'])) {
                    $cloned->setImageCompressionQuality($options['quality']);
                }
                break;
            case 'jpg':
            case 'jpeg':
                if (isset($options['quality'])) {
                    $cloned->setImageCompressionQuality($options['quality']);
                }
                break;
            case 'png':
                if (isset($options['quality'])) {
                    // PNG quality in ImageMagick works differently (0-9 scale)
                    $pngQuality = round((100 - $options['quality']) / 10);
                    $cloned->setImageCompressionQuality($pngQuality);
                }
                break;
        }

        return $cloned->getImageBlob();
    }

    public function getResource(): Imagick
    {
        return $this->imagick;
    }
}
