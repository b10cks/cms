<?php

namespace Tests\Feature\Api;

use App\Services\Image\Dto\ImageTransformation;
use App\Services\Image\ImageTransformationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        Storage::disk('local')->putFileAs(
            'space/asset',
            UploadedFile::fake()->image('image.jpg', 64, 64),
            'image.jpg',
        );
    }

    #[Test]
    public function it_rejects_invalid_quality_before_processing(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldNotReceive('processImage');
        $this->app->instance(ImageTransformationService::class, $service);

        $this->getJson('/ilum/storage/space/asset/image.jpg/w_200?quality=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quality']);
    }

    #[Test]
    public function it_clamps_dimensions_and_passes_quality_to_the_image_service(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldReceive('processImage')
            ->once()
            ->withArgs(function ($disk, $fullPath, $transformation): bool {
                return $fullPath === 'space/asset/image.jpg'
                    && $transformation instanceof ImageTransformation
                    && $transformation->operation === 'resize'
                    && $transformation->format === 'png'
                    && $transformation->quality === 42
                    && $transformation->params['width'] === 5000
                    && $transformation->params['height'] === 5000;
            })
            ->andReturn([
                'data' => 'transformed-image',
                'format' => 'png',
                'mime' => 'image/png',
            ]);
        $this->app->instance(ImageTransformationService::class, $service);

        $response = $this->get('/ilum/storage/space/asset/image.jpg/w_9000,h_9000?format=png&quality=42');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control');

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', (string) $cacheControl);
        $this->assertStringContainsString('max-age=31536000', (string) $cacheControl);
        $this->assertStringContainsString('immutable', (string) $cacheControl);
    }

    #[Test]
    public function it_returns_not_found_for_missing_local_files(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldNotReceive('processImage');
        $this->app->instance(ImageTransformationService::class, $service);

        $this->getJson('/ilum/storage/space/asset/missing.jpg')
            ->assertNotFound();
    }
}
