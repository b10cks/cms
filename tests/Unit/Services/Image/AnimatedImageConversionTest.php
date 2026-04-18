<?php

namespace Tests\Unit\Services\Image;

use App\Contracts\Image\ImageDriverInterface;
use App\Contracts\Image\ImageInterface;
use App\Services\Image\ImageTransformationManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnimatedImageConversionTest extends TestCase
{
    #[Test]
    public function animated_images_fall_back_to_gif_when_the_default_format_cannot_animate(): void
    {
        $this->assertSame('gif', $this->makeManager('png')->determine(null, $this->makeDriver(), $this->makeImage(true)));
    }

    #[Test]
    public function animated_images_keep_animation_capable_defaults(): void
    {
        $this->assertSame('webp', $this->makeManager('webp')->determine(null, $this->makeDriver(), $this->makeImage(true)));
    }

    #[Test]
    public function explicit_requested_formats_still_win(): void
    {
        $this->assertSame('png', $this->makeManager('webp')->determine('png', $this->makeDriver(), $this->makeImage(true)));
    }

    private function makeManager(string $defaultFormat): ImageTransformationManager
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'ilum.default_format' => $defaultFormat,
        ]));

        return new class($container) extends ImageTransformationManager
        {
            public function determine(?string $requestedFormat, ImageDriverInterface $driver, ImageInterface $image): string
            {
                return $this->determineOutputFormat($requestedFormat, $driver, $image);
            }
        };
    }

    private function makeDriver(): ImageDriverInterface
    {
        return new class implements ImageDriverInterface
        {
            public function loadFromFile(string $path): ImageInterface
            {
                throw new \BadMethodCallException();
            }

            public function loadFromBuffer($buffer): ImageInterface
            {
                throw new \BadMethodCallException();
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedFormats(): array
            {
                return ['webp', 'gif', 'png'];
            }
        };
    }

    private function makeImage(bool $animated): ImageInterface
    {
        return new class($animated) implements ImageInterface
        {
            public function __construct(private readonly bool $animated)
            {
            }

            public function getWidth(): int
            {
                return 4;
            }

            public function getHeight(): int
            {
                return 4;
            }

            public function isAnimated(): bool
            {
                return $this->animated;
            }

            public function resize(?int $width, ?int $height): self
            {
                return $this;
            }

            public function crop(int $x, int $y, int $width, int $height): self
            {
                return $this;
            }

            public function fit(int $width, int $height): self
            {
                return $this;
            }

            public function smartFit(int $width, int $height): self
            {
                return $this;
            }

            public function fitFocus(float $focusX, float $focusY, int $width, int $height): self
            {
                return $this;
            }

            public function cropResize(int $x, int $y, int $width, int $height, int $targetWidth, int $targetHeight): self
            {
                return $this;
            }

            public function toBuffer(string $format, array $options = []): string
            {
                return '';
            }

            public function getResource(): mixed
            {
                return null;
            }
        };
    }
}
