<?php

namespace Tests\Unit\Services\Image\Images;

use App\Services\Image\Images\VipsImage;
use Jcupitt\Vips\Image as VipsImageLib;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VipsImageTest extends TestCase
{
    #[Test]
    public function it_resizes_images_up_when_target_dimensions_are_larger(): void
    {
        $this->skipWhenVipsIsUnavailable();

        $image = $this->makeImage(800, 600);

        $resized = $image->resize(1200, 750);

        $this->assertSame(1000, $resized->getWidth());
        $this->assertSame(750, $resized->getHeight());
    }

    #[Test]
    public function it_can_fit_images_to_larger_dimensions(): void
    {
        $this->skipWhenVipsIsUnavailable();

        $image = $this->makeImage(800, 600);

        $fitted = $image->fit(1200, 750);

        $this->assertSame(1200, $fitted->getWidth());
        $this->assertSame(750, $fitted->getHeight());
    }

    #[Test]
    public function it_can_smart_fit_images_to_larger_dimensions(): void
    {
        $this->skipWhenVipsIsUnavailable();

        $image = $this->makeImage(800, 600);

        $fitted = $image->smartFit(1200, 750);

        $this->assertSame(1200, $fitted->getWidth());
        $this->assertSame(750, $fitted->getHeight());
    }

    #[Test]
    public function it_can_apply_focus_fit_to_larger_dimensions(): void
    {
        $this->skipWhenVipsIsUnavailable();

        $image = $this->makeImage(800, 600);

        $fitted = $image->fitFocus(50, 50, 1200, 750);

        $this->assertSame(1200, $fitted->getWidth());
        $this->assertSame(750, $fitted->getHeight());
    }

    private function makeImage(int $width, int $height): VipsImage
    {
        return new VipsImage(VipsImageLib::black($width, $height));
    }

    private function skipWhenVipsIsUnavailable(): void
    {
        if (! extension_loaded('vips')) {
            $this->markTestSkipped('The vips extension is not installed.');
        }
    }
}
