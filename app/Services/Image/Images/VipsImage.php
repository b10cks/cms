<?php

namespace App\Services\Image\Images;

use App\Contracts\Image\ImageInterface;
use Jcupitt\Vips\Interesting;
use Jcupitt\Vips\Image as VipsImageLib;

class VipsImage implements ImageInterface
{
    public function __construct(
        private VipsImageLib $image
    )
    {
    }

    public function getWidth(): int
    {
        return $this->image->width;
    }

    public function getHeight(): int
    {
        return $this->image->height;
    }

    public function resize(?int $width, ?int $height): ImageInterface
    {
        $scale = $this->resolveResizeScale($width, $height);
        $resized = $this->image->resize($scale);
        return new static($resized);
    }

    public function crop(int $x, int $y, int $width, int $height): ImageInterface
    {
        $cropped = $this->image->crop($x, $y, $width, $height);
        return new static($cropped);
    }

    public function fit(int $width, int $height): ImageInterface
    {
        $resized = $this->resizeToCover($width, $height);
        $cropped = $this->cropCentered($resized, $width, $height);

        return new static($cropped);
    }

    public function smartFit(int $width, int $height): ImageInterface
    {
        $resized = $this->resizeToCover($width, $height);
        $cropped = $resized->smartcrop($width, $height, [
            'interesting' => Interesting::ATTENTION,
        ]);

        return new static($cropped);
    }

    public function fitFocus(float $focusX, float $focusY, int $width, int $height): ImageInterface
    {
        $resized = $this->resizeToCover($width, $height);
        $resizedWidth = $resized->width;
        $resizedHeight = $resized->height;

        // Calculate focus point in new dimensions
        $focusXPixel = (int) round(($focusX / 100) * $resizedWidth);
        $focusYPixel = (int) round(($focusY / 100) * $resizedHeight);

        // Calculate crop position
        $leftOffset = max(0, min((int) round($focusXPixel - ($width / 2)), $resizedWidth - $width));
        $topOffset = max(0, min((int) round($focusYPixel - ($height / 2)), $resizedHeight - $height));

        $cropped = $resized->crop($leftOffset, $topOffset, $width, $height);
        return new static($cropped);
    }

    public function cropResize(int $x, int $y, int $width, int $height, int $targetWidth, int $targetHeight): ImageInterface
    {
        $cropped = $this->image->crop($x, $y, $width, $height);
        $resized = $cropped->thumbnail_image($targetWidth, ['height' => $targetHeight, 'size' => 'force']);
        return new static($resized);
    }

    public function toBuffer(string $format, array $options = []): string
    {
        $vipsOptions = [];

        switch ($format) {
            case 'webp':
                if (isset($options['quality'])) {
                    $vipsOptions['Q'] = $options['quality'];
                }
                $vipsOptions['lossless'] = $options['lossless'] ?? false;
                break;
            case 'avif':
                if (isset($options['quality'])) {
                    $vipsOptions['Q'] = $options['quality'];
                }
                break;
            case 'jpg':
            case 'jpeg':
                if (isset($options['quality'])) {
                    $vipsOptions['Q'] = $options['quality'];
                }
                break;
            case 'png':
                if (isset($options['quality'])) {
                    $vipsOptions['Q'] = $options['quality'];
                }
                break;
        }

        return $this->image->writeToBuffer(".{$format}", $vipsOptions);
    }

    public function getResource(): VipsImageLib
    {
        return $this->image;
    }

    private function resolveResizeScale(?int $width, ?int $height): float
    {
        $originalWidth = $this->image->width;
        $originalHeight = $this->image->height;

        $scale = match (true) {
            $width !== null && $height !== null => min($width / $originalWidth, $height / $originalHeight),
            $width !== null => $width / $originalWidth,
            $height !== null => $height / $originalHeight,
            default => 1.0,
        };

        return max($scale, 0.0001);
    }

    private function resizeToCover(int $width, int $height): VipsImageLib
    {
        $scale = max($width / $this->image->width, $height / $this->image->height);

        return $this->image->resize($scale);
    }

    private function cropCentered(VipsImageLib $image, int $width, int $height): VipsImageLib
    {
        $leftOffset = max((int) floor(($image->width - $width) / 2), 0);
        $topOffset = max((int) floor(($image->height - $height) / 2), 0);

        return $image->crop($leftOffset, $topOffset, $width, $height);
    }
}
