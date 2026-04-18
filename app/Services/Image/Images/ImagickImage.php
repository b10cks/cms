<?php

namespace App\Services\Image\Images;

use App\Contracts\Image\ImageInterface;
use Imagick;

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

    public function isAnimated(): bool
    {
        return $this->imagick->getNumberImages() > 1;
    }

    public function resize(?int $width, ?int $height): ImageInterface
    {
        [$targetWidth, $targetHeight] = $this->resolveResizeDimensions($width, $height);

        $cloned = $this->isAnimated()
            ? $this->transformAnimatedFrames(function (Imagick $frame) use ($targetWidth, $targetHeight): void {
                $frame->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
            })
            : clone $this->imagick;

        if (! $this->isAnimated()) {
            $cloned->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
        }

        return new static($cloned);
    }

    public function crop(int $x, int $y, int $width, int $height): ImageInterface
    {
        $cloned = $this->isAnimated()
            ? $this->transformAnimatedFrames(function (Imagick $frame) use ($x, $y, $width, $height): void {
                $frame->cropImage($width, $height, $x, $y);
            })
            : clone $this->imagick;

        if (! $this->isAnimated()) {
            $cloned->cropImage($width, $height, $x, $y);
        }

        return new static($cloned);
    }

    public function fit(int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->transformAnimatedFrames(
                function (Imagick $frame) use ($width, $height): void {
                    $this->applyFitToFrame($frame, $width, $height);
                }
            ));
        }

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

    public function fitFocus(float $focusX, float $focusY, int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->transformAnimatedFrames(
                function (Imagick $frame) use ($focusX, $focusY, $width, $height): void {
                    $this->applyFitFocusToFrame($frame, $focusX, $focusY, $width, $height);
                }
            ));
        }

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
        $cloned = $this->isAnimated()
            ? $this->transformAnimatedFrames(function (Imagick $frame) use ($x, $y, $width, $height, $targetWidth, $targetHeight): void {
                $frame->cropImage($width, $height, $x, $y);
                $frame->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
            })
            : clone $this->imagick;

        if (! $this->isAnimated()) {
            $cloned->cropImage($width, $height, $x, $y);
            $cloned->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
        }

        return new static($cloned);
    }

    public function toBuffer(string $format, array $options = []): string
    {
        $cloned = clone $this->imagick;

        if ($this->isAnimated()) {
            $cloned = $cloned->coalesceImages();

            if ($this->supportsAnimation($format)) {
                foreach ($cloned as $frame) {
                    $this->applyFormatOptionsToFrame($frame, $format, $options);
                }

                if ($format === 'gif') {
                    $cloned = $cloned->deconstructImages();
                }

                return $cloned->getImagesBlob();
            }

            $cloned->setFirstIterator();
        }

        $this->applyFormatOptionsToFrame($cloned, $format, $options);

        return $cloned->getImageBlob();
    }

    public function getResource(): Imagick
    {
        return $this->imagick;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveResizeDimensions(?int $width, ?int $height): array
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();

        $scale = match (true) {
            $width !== null && $height !== null => min($width / $originalWidth, $height / $originalHeight),
            $width !== null => $width / $originalWidth,
            $height !== null => $height / $originalHeight,
            default => 1.0,
        };

        $scale = max(min($scale, 1.0), 0.0001);

        return [
            max((int) round($originalWidth * $scale), 1),
            max((int) round($originalHeight * $scale), 1),
        ];
    }

    private function transformAnimatedFrames(callable $transform): Imagick
    {
        $sequence = (clone $this->imagick)->coalesceImages();

        foreach ($sequence as $frame) {
            $transform($frame);
            $frame->setImagePage(0, 0, 0, 0);
        }

        return $sequence;
    }

    private function applyFitToFrame(Imagick $frame, int $width, int $height): void
    {
        $originalWidth = $frame->getImageWidth();
        $originalHeight = $frame->getImageHeight();
        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        if ($ratioOriginal > $ratioTarget) {
            $newHeight = $height;
            $newWidth = (int) round($height * $ratioOriginal);
            $frame->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

            $leftOffset = (int) round(($newWidth - $width) / 2);
            $frame->cropImage($width, $newHeight, $leftOffset, 0);

            return;
        }

        $newWidth = $width;
        $newHeight = (int) round($width / $ratioOriginal);
        $frame->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

        $topOffset = (int) round(($newHeight - $height) / 2);
        $frame->cropImage($newWidth, $height, 0, $topOffset);
    }

    private function applyFitFocusToFrame(
        Imagick $frame,
        float $focusX,
        float $focusY,
        int $width,
        int $height,
    ): void {
        $originalWidth = $frame->getImageWidth();
        $originalHeight = $frame->getImageHeight();

        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget = $width / $height;

        if ($ratioOriginal > $ratioTarget) {
            $newHeight = $height;
            $newWidth = (int) round($height * $ratioOriginal);
        } else {
            $newWidth = $width;
            $newHeight = (int) round($width / $ratioOriginal);
        }

        $frame->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

        $focusXPixel = (int) round(($focusX / 100) * $originalWidth);
        $focusYPixel = (int) round(($focusY / 100) * $originalHeight);

        $focusXPixel = (int) round($focusXPixel * ($newWidth / $originalWidth));
        $focusYPixel = (int) round($focusYPixel * ($newHeight / $originalHeight));

        $leftOffset = (int) max(0, min($focusXPixel - ($width / 2), $newWidth - $width));
        $topOffset = (int) max(0, min($focusYPixel - ($height / 2), $newHeight - $height));

        $frame->cropImage($width, $height, $leftOffset, $topOffset);
    }

    private function applyFormatOptionsToFrame(Imagick $frame, string $format, array $options): void
    {
        $frame->setImageFormat($format);

        switch ($format) {
            case 'webp':
            case 'jpg':
            case 'jpeg':
                if (isset($options['quality'])) {
                    $frame->setImageCompressionQuality($options['quality']);
                }
                break;
            case 'png':
                if (isset($options['quality'])) {
                    $pngQuality = round((100 - $options['quality']) / 10);
                    $frame->setImageCompressionQuality($pngQuality);
                }
                break;
        }
    }

    private function supportsAnimation(string $format): bool
    {
        return \in_array($format, ['gif', 'webp'], true);
    }
}
