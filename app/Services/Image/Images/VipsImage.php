<?php

namespace App\Services\Image\Images;

use App\Contracts\Image\ImageInterface;
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
        $originalWidth = $this->image->width;
        $originalHeight = $this->image->height;

        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        if ($ratioOriginal > $ratioTarget) {
            // Image is wider than target ratio
            $newHeight = $height;
            $newWidth = round($height * $ratioOriginal);
            $resized = $this->image->thumbnail_image($newWidth, ['height' => $newHeight, 'size' => 'down']);

            $leftOffset = ($newWidth - $width) / 2;
            $cropped = $resized->crop($leftOffset, 0, $width, $newHeight);
        } else {
            // Image is taller than target ratio
            $newWidth = $width;
            $newHeight = round($width / $ratioOriginal);
            $resized = $this->image->thumbnail_image($newWidth, ['height' => $newHeight, 'size' => 'down']);

            $topOffset = ($newHeight - $height) / 2;
            $cropped = $resized->crop(0, $topOffset, $newWidth, $height);
        }

        return new static($cropped);
    }

    public function smartFit(int $width, int $height): ImageInterface
    {
        $thumbnail = $this->image->thumbnail_image($width, [
            'height' => $height,
            'crop' => 'attention',
            'size' => 'down',
        ]);
        return new static($thumbnail);
    }

    public function fitFocus(float $focusX, float $focusY, int $width, int $height): ImageInterface
    {
        $originalWidth = $this->image->width;
        $originalHeight = $this->image->height;

        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        if ($ratioOriginal > $ratioTarget) {
            $newHeight = $height;
            $newWidth = round($height * $ratioOriginal);
        } else {
            $newWidth = $width;
            $newHeight = round($width / $ratioOriginal);
        }

        $resized = $this->image->thumbnail_image($newWidth, ['height' => $newHeight, 'size' => 'down']);

        // Calculate focus point in new dimensions
        $focusXPixel = round(($focusX / 100) * $originalWidth);
        $focusYPixel = round(($focusY / 100) * $originalHeight);

        $focusXPixel = round($focusXPixel * ($newWidth / $originalWidth));
        $focusYPixel = round($focusYPixel * ($newHeight / $originalHeight));

        // Calculate crop position
        $leftOffset = max(0, min($focusXPixel - ($width / 2), $newWidth - $width));
        $topOffset = max(0, min($focusYPixel - ($height / 2), $newHeight - $height));

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

        return max(min($scale, 1.0), 0.0001);
    }
}
