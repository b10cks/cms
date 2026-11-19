<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\Dto\ImageTransformation;
use App\Services\Image\ImageTransformationManager;
use Illuminate\Support\Facades\Storage;
use Jcupitt\Vips\Image as VipsImage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Medium format cameras deliver 100 MP and more. Such a source is shrunk while
 * it is decoded and streamed through the pipeline, and both must leave the
 * result as it was.
 */
class LargeSourceImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            VipsImage::black(1, 1);
        } catch (\Throwable) {
            $this->markTestSkipped('libvips is not available.');
        }

        config(['ilum.driver' => 'vips']);
        Storage::fake('images');
    }

    #[Test]
    public function a_photo_over_100_megapixels_is_resized(): void
    {
        $this->putJpeg('photo.jpg', VipsImage::black(10000, 10100, ['bands' => 3]));

        $output = $this->process('photo.jpg', 'resize', ['width' => 640, 'height' => null]);

        $this->assertSame([640, 646], [$output->width, $output->height]);
    }

    #[Test]
    public function a_source_shrunk_on_load_is_still_turned_upright(): void
    {
        // Stored as 800x400 with the tag for a quarter turn: displayed as 400x800.
        $sideways = VipsImage::black(800, 400, ['bands' => 3])->copy();
        $sideways->set('orientation', 6);
        $this->putJpeg('sideways.jpg', $sideways);

        $output = $this->process('sideways.jpg', 'resize', ['width' => 20, 'height' => null]);

        $this->assertSame([20, 40], [$output->width, $output->height]);
    }

    #[Test]
    public function a_streamed_source_can_be_smart_cropped(): void
    {
        $this->putJpeg('photo.jpg', VipsImage::black(900, 600, ['bands' => 3]));

        $output = $this->process('photo.jpg', 'smartfit', ['width' => 300, 'height' => 300]);

        $this->assertSame([300, 300], [$output->width, $output->height]);
    }

    /** @param  array<string, int|null>  $params */
    private function process(string $path, string $operation, array $params): VipsImage
    {
        $result = app(ImageTransformationManager::class)->processImage(
            Storage::disk('images'),
            $path,
            new ImageTransformation($operation, $params, 'jpg'),
        );

        $this->assertNotNull($result);

        return VipsImage::newFromBuffer($result['data']);
    }

    private function putJpeg(string $path, VipsImage $image): void
    {
        Storage::disk('images')->put($path, $image->cast('uchar')->jpegsave_buffer());
    }
}
