<?php

namespace Tests\Feature\Api;

use App\Exceptions\ImageSourceTooLargeException;
use App\Services\Image\Dto\ImageTransformation;
use App\Services\Image\ImageTransformationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
            'users/avatars',
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

        $this->getJson('/ilum/storage/users/avatars/image.jpg/w_200?quality=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quality'], 'details');
    }

    #[Test]
    public function it_clamps_dimensions_and_passes_quality_to_the_image_service(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldReceive('processImage')
            ->once()
            ->withArgs(function ($disk, $fullPath, $transformation): bool {
                return $fullPath === 'users/avatars/image.jpg'
                    && $transformation instanceof ImageTransformation
                    && $transformation->operation === 'fit'
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

        $response = $this->get('/ilum/storage/users/avatars/image.jpg/w_9000,h_9000?format=png&quality=42');

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
    public function it_accepts_jpeg_as_another_name_for_jpg(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldReceive('processImage')
            ->once()
            ->withArgs(fn ($disk, $fullPath, $transformation): bool => $transformation->format === 'jpg')
            ->andReturn(['data' => 'transformed-image', 'format' => 'jpg', 'mime' => 'image/jpeg']);
        $this->app->instance(ImageTransformationService::class, $service);

        $this->get('/ilum/storage/users/avatars/image.jpg/w_200?format=jpeg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    #[Test]
    public function a_source_over_the_pixel_limit_is_unprocessable_not_missing(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldReceive('processImage')->once()->andThrow(new ImageSourceTooLargeException(300_000_000));
        $this->app->instance(ImageTransformationService::class, $service);

        $this->getJson('/ilum/storage/users/avatars/image.jpg/w_200')
            ->assertUnprocessable()
            ->assertExactJson(['error' => 'Image exceeds the 300 megapixel limit for transformations']);
    }

    #[Test]
    public function a_failed_transformation_is_a_server_error_with_the_reason_logged(): void
    {
        Log::spy();

        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldReceive('processImage')->once()->andThrow(new \RuntimeException('VipsJpeg: premature end of JPEG image'));
        $this->app->instance(ImageTransformationService::class, $service);

        $this->getJson('/ilum/storage/users/avatars/image.jpg/w_200')
            ->assertStatus(500)
            ->assertExactJson(['error' => 'Error processing image']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'premature end of JPEG image')
                && $context['path'] === 'users/avatars/image.jpg')
            ->once();
    }

    #[Test]
    public function it_returns_not_found_for_missing_local_files(): void
    {
        $service = Mockery::mock(ImageTransformationService::class);
        $service->shouldNotReceive('processImage');
        $this->app->instance(ImageTransformationService::class, $service);

        $this->getJson('/ilum/storage/users/avatars/missing.jpg')
            ->assertNotFound();
    }
}
