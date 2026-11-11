<?php

namespace App\Services\Image\Images;

use App\Contracts\Image\ImageInterface;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\ForeignKeep;
use Jcupitt\Vips\ForeignSubsample;
use Jcupitt\Vips\Image as VipsImageLib;
use Jcupitt\Vips\Interesting;

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
        return $this->getPageHeight();
    }

    public function isAnimated(): bool
    {
        return $this->getPageCount() > 1 && $this->image->getType('page-height') !== 0;
    }

    public function resize(?int $width, ?int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->resize($width, $height)->getResource()
            ));
        }

        $scale = $this->resolveResizeScale($width, $height);
        $resized = $this->image->resize($scale);
        return new static($resized);
    }

    public function crop(int $x, int $y, int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->crop($x, $y, $width, $height)->getResource()
            ));
        }

        $cropped = $this->image->crop($x, $y, $width, $height);
        return new static($cropped);
    }

    public function fit(int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->fit($width, $height)->getResource()
            ));
        }

        $resized = $this->resizeToCover($width, $height);
        $cropped = $this->cropCentered($resized, $width, $height);

        return new static($cropped);
    }

    public function smartFit(int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->smartFit($width, $height)->getResource()
            ));
        }

        $resized = $this->resizeToCover($width, $height);
        $cropped = $resized->smartcrop($width, $height, [
            'interesting' => Interesting::ATTENTION,
        ]);

        return new static($cropped);
    }

    public function fitFocus(float $focusX, float $focusY, int $width, int $height): ImageInterface
    {
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->fitFocus($focusX, $focusY, $width, $height)->getResource()
            ));
        }

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
        if ($this->isAnimated()) {
            return new static($this->mapAnimatedFrames(
                fn (VipsImageLib $frame): VipsImageLib => (new static($frame))->cropResize(
                    $x,
                    $y,
                    $width,
                    $height,
                    $targetWidth,
                    $targetHeight,
                )->getResource()
            ));
        }

        $cropped = $this->image->crop($x, $y, $width, $height);
        $resized = $cropped->thumbnail_image($targetWidth, ['height' => $targetHeight, 'size' => 'force']);
        return new static($resized);
    }

    public function toBuffer(string $format, array $options = []): string
    {
        if ($this->isAnimated() && ! $this->supportsAnimation($format)) {
            return (new static($this->extractFrame(0)))->toBuffer($format, $options);
        }

        // Derivatives carry pixels only: no EXIF, XMP, IPTC, comments or
        // colour profile. toSrgb() is what makes dropping the profile safe.
        return $this->toSrgb()->writeToBuffer(".{$format}", [
            'keep' => ForeignKeep::NONE,
            ...$this->encoderOptions($format, $options['quality'] ?? null),
        ]);
    }

    /**
     * Encoder settings, picked by measuring size against SSIMULACRA2 and
     * encode time on photos and graphics from 80 to 1920 pixels wide.
     *
     * @return array<string, mixed>
     */
    private function encoderOptions(string $format, ?int $quality): array
    {
        $options = match ($format) {
            // Sharper chroma on coloured edges. Effort 6 saves 5% for twice the time.
            'webp' => ['Q' => $quality, 'smart_subsample' => true],
            // Effort 4, the libvips default, takes 8 times as long for 5% fewer
            // bytes: seconds for a large photo. 4:4:4 costs photos nothing per
            // byte and keeps graphics crisp.
            'avif' => ['Q' => $quality, 'effort' => 2, 'subsample_mode' => ForeignSubsample::OFF],
            // Progressive scans and optimized Huffman tables: 5% smaller.
            'jpg', 'jpeg' => ['Q' => $quality, 'optimize_coding' => true, 'interlace' => true],
            // PNG stays lossless, so it has no quality.
            default => [],
        };

        return array_filter($options, fn (mixed $value): bool => $value !== null);
    }

    /**
     * Convert a source with a colour profile to sRGB, which is what browsers
     * assume for an image without one. Dropping the profile of a Display P3
     * or Adobe RGB photo without converting would shift its colours.
     */
    private function toSrgb(): VipsImageLib
    {
        if ($this->image->getType('icc-profile-data') === 0) {
            return $this->image;
        }

        try {
            return $this->image->icc_transform('srgb', ['embedded' => true]);
        } catch (VipsException) {
            // A profile lcms cannot use: read the pixels as sRGB, like a
            // browser does with a broken profile.
            return $this->image;
        }
    }

    public function getResource(): VipsImageLib
    {
        return $this->image;
    }

    private function mapAnimatedFrames(callable $transform): VipsImageLib
    {
        $frames = [];
        $pageCount = $this->getPageCount();

        for ($index = 0; $index < $pageCount; $index++) {
            $frames[] = $transform($this->extractFrame($index));
        }

        $joined = VipsImageLib::arrayjoin($frames, ['across' => 1]);

        return $this->applyAnimationMetadata($joined, $frames[0]->height);
    }

    private function resolveResizeScale(?int $width, ?int $height): float
    {
        $originalWidth = $this->image->width;
        $originalHeight = $this->getHeight();

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
        $scale = max($width / $this->image->width, $height / $this->getHeight());

        return $this->image->resize($scale);
    }

    private function cropCentered(VipsImageLib $image, int $width, int $height): VipsImageLib
    {
        $leftOffset = max((int) floor(($image->width - $width) / 2), 0);
        $topOffset = max((int) floor(($image->height - $height) / 2), 0);

        return $image->crop($leftOffset, $topOffset, $width, $height);
    }

    private function extractFrame(int $index): VipsImageLib
    {
        $frame = $this->image->crop(
            0,
            $index * $this->getPageHeight(),
            $this->image->width,
            $this->getPageHeight(),
        );

        foreach (['n-pages', 'page-height', 'delay', 'loop'] as $field) {
            if ($frame->getType($field) !== 0) {
                $frame->remove($field);
            }
        }

        return $frame;
    }

    private function applyAnimationMetadata(VipsImageLib $image, int $pageHeight): VipsImageLib
    {
        $image->set('page-height', $pageHeight);
        $image->set('n-pages', $this->getPageCount());

        foreach (['delay', 'loop'] as $field) {
            if ($this->image->getType($field) !== 0) {
                $image->set($field, $this->image->get($field));
            }
        }

        return $image;
    }

    private function getPageCount(): int
    {
        if ($this->image->getType('n-pages') === 0) {
            return 1;
        }

        return max((int) $this->image->get('n-pages'), 1);
    }

    private function getPageHeight(): int
    {
        if ($this->image->getType('page-height') === 0) {
            return $this->image->height;
        }

        return max((int) $this->image->get('page-height'), 1);
    }

    private function supportsAnimation(string $format): bool
    {
        return \in_array($format, ['gif', 'webp'], true);
    }
}
