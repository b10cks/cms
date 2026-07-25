<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ImageController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\User;
use App\Services\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Covers poster delivery through the ilum URL grammar and the custom poster
 * upload that replaces the auto-generated frames.
 */
#[CoversClass(ImageController::class)]
class PosterDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => [
                'root' => storage_path("app/spaces/{$this->space->id}"),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    protected function tearDown(): void
    {
        $root = storage_path("app/spaces/{$this->space->id}");

        if (is_dir($root)) {
            exec('rm -rf '.escapeshellarg($root));
        }

        parent::tearDown();
    }

    private function createVideoAsset(int $frames = 2): Asset
    {
        $asset = Asset::create([
            'filename' => 'clip',
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'storage_id' => $this->storage->id,
            'size' => 12,
            'metadata' => ['type' => 'video'],
        ]);

        $asset->path = "{$this->space->id}/{$asset->id}/clip.mp4";

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $disk->put($asset->path, 'video-bytes');

        $thumbnails = [];

        for ($i = 0; $i < $frames; $i++) {
            $path = "{$this->space->id}/{$asset->id}/clip_thumbnail_{$i}.jpg";
            $disk->put($path, (string) UploadedFile::fake()->image("frame{$i}.jpg", 40, 30)->get());

            $thumbnails[] = [
                'path' => $path,
                'position' => $i * 5,
                'position_formatted' => sprintf('00:%02d', $i * 5),
            ];
        }

        $asset->metadata = [...$asset->metadata, 'thumbnails' => $thumbnails];
        $asset->save();

        return $asset;
    }

    private function posterUrl(Asset $asset, string $suffix = ''): string
    {
        return "/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4/poster{$suffix}";
    }

    #[Test]
    public function it_serves_the_first_frame_as_the_poster(): void
    {
        $asset = $this->createVideoAsset();

        $response = $this->get($this->posterUrl($asset));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function it_serves_a_specific_frame(): void
    {
        $asset = $this->createVideoAsset(3);

        $this->get($this->posterUrl($asset).'?frame=2')->assertOk();
    }

    #[Test]
    public function it_falls_back_to_the_first_frame_for_an_out_of_range_index(): void
    {
        $asset = $this->createVideoAsset(2);

        $this->get($this->posterUrl($asset).'?frame=99')->assertOk();
    }

    #[Test]
    public function it_applies_image_transformations_to_the_poster(): void
    {
        $asset = $this->createVideoAsset();

        $this->get($this->posterUrl($asset, '/w_20,h_20,c_fill'))
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
    }

    #[Test]
    public function it_returns_404_when_the_asset_has_no_poster(): void
    {
        $asset = $this->createVideoAsset(0);

        $this->get($this->posterUrl($asset))->assertNotFound();
    }

    #[Test]
    public function it_keeps_unpinned_posters_short_lived_but_pins_versioned_ones(): void
    {
        $asset = $this->createVideoAsset();

        $unpinned = (string) $this->get($this->posterUrl($asset))->headers->get('cache-control');
        $pinned = (string) $this->get($this->posterUrl($asset).'?v=abc123')->headers->get('cache-control');

        $this->assertStringContainsString('max-age=3600', $unpinned);
        $this->assertStringNotContainsString('immutable', $unpinned);
        $this->assertStringContainsString('immutable', $pinned);
    }

    #[Test]
    public function uploading_a_poster_replaces_the_generated_frames(): void
    {
        $asset = $this->createVideoAsset(3);
        $originalPaths = array_column($asset->metadata['thumbnails'], 'path');

        $response = $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->image('custom.jpg', 200, 120)],
        );

        $response->assertOk();

        $thumbnails = $response->json('metadata.thumbnails');
        $this->assertCount(1, $thumbnails);
        $this->assertTrue($thumbnails[0]['custom']);
        $this->assertNotContains($thumbnails[0]['path'], $originalPaths);

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $this->assertTrue($disk->exists($thumbnails[0]['path']));

        // The frames the editor rejected are cleaned up rather than orphaned.
        foreach ($originalPaths as $path) {
            $this->assertFalse($disk->exists($path));
        }
    }

    #[Test]
    public function the_uploaded_poster_is_what_the_poster_endpoint_serves(): void
    {
        $asset = $this->createVideoAsset();

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->image('custom.png', 200, 120)],
        )->assertOk();

        $this->get($this->posterUrl($asset))->assertOk();
        // Only the uploaded frame remains, so frame=1 collapses back to it.
        $this->get($this->posterUrl($asset).'?frame=1')->assertOk();
    }

    #[Test]
    public function it_exposes_a_versioned_poster_url_on_the_asset_resource(): void
    {
        $asset = $this->createVideoAsset();

        $posterUrl = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}")
            ->assertOk()
            ->json('poster_url');

        $this->assertNotNull($posterUrl);
        $this->assertStringContainsString('/poster?', $posterUrl);
        $this->assertStringContainsString('v=', $posterUrl);
    }

    #[Test]
    public function it_rejects_a_poster_on_a_non_media_asset(): void
    {
        $asset = Asset::create([
            'filename' => 'photo',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'storage_id' => $this->storage->id,
            'size' => 10,
            'metadata' => [],
        ]);
        $asset->path = "{$this->space->id}/{$asset->id}/photo.jpg";
        $asset->save();

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->image('custom.jpg')],
        )->assertStatus(422)->assertJsonPath('code', 'poster_not_supported');
    }

    #[Test]
    public function it_rejects_a_non_image_poster(): void
    {
        $asset = $this->createVideoAsset();

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        )->assertStatus(422)->assertJsonValidationErrors(['poster']);
    }
}
