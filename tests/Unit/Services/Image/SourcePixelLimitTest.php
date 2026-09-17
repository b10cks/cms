<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\Dto\ImageTransformation;
use App\Services\Image\ImageTransformationManager;
use Illuminate\Support\Facades\Storage;
use Jcupitt\Vips\Image as VipsImage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pixel cap protects against decompression bombs. An animation whose
 * frames only add up past the cap is not a bomb, and used to fail on every
 * uncached request.
 */
class SourcePixelLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            VipsImage::black(1, 1);
        } catch (\Throwable) {
            $this->markTestSkipped('libvips is not available.');
        }

        config(['ilum.driver' => 'vips', 'ilum.max_source_pixels' => 300]);
        Storage::fake('images');
    }

    #[Test]
    public function an_animation_over_the_cap_is_delivered_as_its_first_frame(): void
    {
        // 10x10 frames: one fits the 300 pixel cap, all five do not.
        $this->putGif('animation.gif', 10, 10, frames: 5);

        $result = $this->process('animation.gif');

        $this->assertNotNull($result);
        $this->assertSame('image/gif', $result['mime']);

        $output = VipsImage::newFromBuffer($result['data'], '', ['n' => -1]);
        $this->assertSame([5, 5], [$output->width, $output->height]);
    }

    #[Test]
    public function an_animation_within_the_cap_keeps_every_frame(): void
    {
        config(['ilum.max_source_pixels' => 500]);
        $this->putGif('animation.gif', 10, 10, frames: 5);

        $result = $this->process('animation.gif');

        $output = VipsImage::newFromBuffer($result['data'], '', ['n' => -1]);
        $this->assertSame(5, (int) $output->get('n-pages'));
    }

    #[Test]
    public function a_single_frame_over_the_cap_is_still_rejected(): void
    {
        $this->putGif('still.gif', 20, 20, frames: 1);

        $this->assertNull($this->process('still.gif'));
    }

    /** @return array{data: string, format: string, mime: string}|null */
    private function process(string $path): ?array
    {
        return app(ImageTransformationManager::class)->processImage(
            Storage::disk('images'),
            $path,
            new ImageTransformation('resize', ['width' => 5, 'height' => 5], 'gif'),
        );
    }

    private function putGif(string $path, int $width, int $height, int $frames): void
    {
        // Distinct frames, so the encoder can't collapse them into one.
        $strip = VipsImage::arrayjoin(
            array_map(fn (int $i) => VipsImage::black($width, $height)->add($i * 40), range(0, $frames - 1)),
            ['across' => 1],
        )->cast('uchar')->copy();
        $strip->set('page-height', $height);

        Storage::disk('images')->put($path, $strip->gifsave_buffer());
    }
}
