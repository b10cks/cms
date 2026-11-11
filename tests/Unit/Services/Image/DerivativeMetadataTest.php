<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\Dto\ImageTransformation;
use App\Services\Image\ImageTransformationManager;
use Illuminate\Support\Facades\Storage;
use Jcupitt\Vips\FFI;
use Jcupitt\Vips\Image as VipsImage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Derivatives are public and cached for a year: they carry pixels only. A
 * camera original otherwise drags its EXIF, XMP and colour profile along,
 * which leaks serial numbers and outweighs a small picture several times.
 */
class DerivativeMetadataTest extends TestCase
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
    public function a_derivative_carries_no_metadata(): void
    {
        $source = $this->colour([200, 100, 50])->icc_transform('srgb', ['input_profile' => 'srgb']);
        $source->set('orientation', 1);
        $source->setType(FFI::gtypes('VipsBlob'), 'xmp-data', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
        Storage::disk('images')->put('photo.jpg', $source->jpegsave_buffer());

        $stored = VipsImage::newFromBuffer(Storage::disk('images')->get('photo.jpg'));
        $this->assertSame([], $this->missingFields($stored), 'The fixture must carry metadata.');

        foreach (['webp', 'avif', 'jpg', 'png'] as $format) {
            $output = VipsImage::newFromBuffer($this->process('photo.jpg', $format)['data']);

            $this->assertSame(
                ['exif-data', 'xmp-data', 'icc-profile-data'],
                $this->missingFields($output),
                "Metadata left in {$format}.",
            );
        }
    }

    #[Test]
    public function exif_orientation_is_applied_to_the_pixels(): void
    {
        // Stored as 20x10 and tagged "rotate 90 degrees clockwise to display".
        $source = $this->colour([200, 100, 50], 20, 10);
        $source->set('orientation', 6);
        Storage::disk('images')->put('rotated.jpg', $source->jpegsave_buffer());

        $output = VipsImage::newFromBuffer($this->process('rotated.jpg', 'png')['data']);

        $this->assertSame([10, 20], [$output->width, $output->height]);
        $this->assertSame(0, $output->getType('orientation'));
    }

    #[Test]
    public function a_wide_gamut_source_is_converted_to_srgb(): void
    {
        // The same colour, encoded for Display P3: about [187, 105, 60].
        $source = $this->colour([200, 100, 50])->icc_transform('p3', ['input_profile' => 'srgb']);
        Storage::disk('images')->put('p3.png', $source->pngsave_buffer());

        $output = VipsImage::newFromBuffer($this->process('p3.png', 'png')['data']);

        $this->assertSame(0, $output->getType('icc-profile-data'));
        $this->assertEqualsWithDelta([200, 100, 50], $output->getpoint(0, 0), 2);
    }

    /** @param  array{int, int, int}  $rgb */
    private function colour(array $rgb, int $width = 16, int $height = 16): VipsImage
    {
        return VipsImage::black($width, $height)
            ->add($rgb)
            ->cast('uchar')
            ->copy(['interpretation' => 'srgb']);
    }

    /** @return list<string> */
    private function missingFields(VipsImage $image): array
    {
        return array_values(array_filter(
            ['exif-data', 'xmp-data', 'icc-profile-data'],
            fn (string $field): bool => $image->getType($field) === 0,
        ));
    }

    /** @return array{data: string, format: string, mime: string} */
    private function process(string $path, string $format): array
    {
        $result = app(ImageTransformationManager::class)->processImage(
            Storage::disk('images'),
            $path,
            new ImageTransformation('original', [], $format),
        );

        $this->assertNotNull($result);

        return $result;
    }
}
