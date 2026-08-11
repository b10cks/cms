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
            'size' => strlen('video-bytes'),
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

    /**
     * The response contract (Content-Length, Content-Range) has to describe
     * the bytes we can actually deliver. A row that has drifted from the
     * object must not be able to make us promise more, because the truncated
     * response would then be cached for the full max-age.
     */
    // --- Tenant isolation ---------------------------------------------------

    /**
     * The storage id in the URL is not just addressing — it scopes the asset
     * lookup. A caller who swaps in another space's storage id must not be
     * able to reach across the tenant boundary with it.
     */
    #[Test]
    public function it_refuses_a_storage_belonging_to_another_space(): void
    {
        $asset = $this->createVideoAsset(0);

        $otherSpace = Space::factory()->create();
        $foreignStorage = Storage::factory()->create([
            'space_id' => $otherSpace->id,
            'is_default' => true,
            'config' => ['root' => storage_path("app/spaces/{$otherSpace->id}")],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->get("/ilum/{$foreignStorage->id}/{$this->space->id}/{$asset->id}/clip.mp4")
            ->assertNotFound();
    }

    #[Test]
    public function it_refuses_an_asset_held_in_a_different_storage(): void
    {
        $asset = $this->createVideoAsset(0);

        $sibling = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => false,
            'config' => ['root' => storage_path("app/spaces/{$this->space->id}")],
            'driver' => 'local',
            'state' => 'live',
        ]);

        // Same space, same bytes on disk, but the row says another storage.
        $this->get("/ilum/{$sibling->id}/{$this->space->id}/{$asset->id}/clip.mp4")
            ->assertNotFound();
    }

    #[Test]
    public function it_returns_404_for_an_unknown_space_or_asset(): void
    {
        $asset = $this->createVideoAsset(0);

        $this->get("/ilum/{$this->storage->id}/does-not-exist/{$asset->id}/clip.mp4")
            ->assertNotFound();

        $this->get("/ilum/{$this->storage->id}/{$this->space->id}/does-not-exist/clip.mp4")
            ->assertNotFound();
    }

    // --- Download name ------------------------------------------------------

    /**
     * The download name comes from upload metadata, which is user-controlled,
     * and lands inside a quoted header parameter. It must not be able to close
     * that quote or inject a line break.
     */
    #[Test]
    public function it_neutralises_a_hostile_filename_in_the_disposition(): void
    {
        $asset = $this->createVideoAsset(0);
        $asset->metadata = [
            ...(array) $asset->metadata,
            'original_filename' => "e\"vil\\\r\nX-Injected: 1.mp4",
        ];
        $asset->save();

        $disposition = (string) $this
            ->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4?download=1")
            ->headers->get('content-disposition');

        [$ascii] = explode('; filename*=', $disposition, 2);

        $this->assertStringStartsWith('attachment; filename="', $ascii);
        $this->assertStringEndsWith('"', $ascii);
        // Exactly the opening and closing quote survive, and nothing else in
        // the ASCII parameter can break out of it.
        $this->assertSame(2, substr_count($ascii, '"'));
        $this->assertStringNotContainsString('\\', $ascii);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    #[Test]
    public function it_offers_a_utf8_filename_alongside_an_ascii_fallback(): void
    {
        $asset = $this->createVideoAsset(0);
        $asset->metadata = [...(array) $asset->metadata, 'original_filename' => 'vidéo.mp4'];
        $asset->save();

        $disposition = (string) $this
            ->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4?download=1")
            ->headers->get('content-disposition');

        // One underscore per non-ASCII *byte*, so the two-byte é becomes two.
        $this->assertStringContainsString('filename="vid__o.mp4"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''vid%C3%A9o.mp4", $disposition);
    }

    #[Test]
    public function it_prefers_the_uploaded_filename_over_the_storage_path(): void
    {
        $asset = $this->createVideoAsset(0);
        $asset->metadata = [...(array) $asset->metadata, 'original_filename' => 'Holiday Clip.mp4'];
        $asset->save();

        $disposition = (string) $this
            ->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4?download=1")
            ->headers->get('content-disposition');

        $this->assertStringContainsString('Holiday Clip.mp4', $disposition);
    }

    // --- Response contract --------------------------------------------------

    #[Test]
    public function it_measures_the_object_rather_than_trusting_the_asset_row(): void
    {
        $asset = $this->createVideoAsset(0);
        $asset->size = 999;
        $asset->save();

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $disk->put($asset->path, 'short');

        $url = "/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4";

        $response = $this->get($url);
        $response->assertOk()->assertHeader('content-length', '5');
        $this->assertSame('short', $response->streamedContent());

        // ...and the range is satisfied against the real size, not the row's.
        $this->get($url, ['Range' => 'bytes=100-200'])->assertStatus(416);
    }

    #[Test]
    public function it_returns_404_when_the_row_survives_but_the_object_is_gone(): void
    {
        $asset = $this->createVideoAsset(0);

        app(StorageService::class)->getDefaultStorage($this->space)->delete($asset->path);

        $this->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/clip.mp4")
            ->assertNotFound();
    }

    /**
     * Non-media types only carry a poster once one was explicitly uploaded —
     * `thumbnails` metadata from other sources must not leak out as one.
     */
    #[Test]
    public function it_does_not_serve_generated_frames_as_a_poster_for_a_non_media_asset(): void
    {
        $asset = $this->createVideoAsset();
        $asset->mime_type = 'application/pdf';
        $asset->save();

        $this->get($this->posterUrl($asset))->assertNotFound();
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

        // The generated frames are stashed (metadata and files) so removing
        // the poster can restore them without re-running ffmpeg.
        $this->assertSame($originalPaths, array_column($response->json('metadata.generated_thumbnails'), 'path'));

        foreach ($originalPaths as $path) {
            $this->assertTrue($disk->exists($path));
        }
    }

    #[Test]
    public function removing_the_poster_restores_the_generated_frames(): void
    {
        $asset = $this->createVideoAsset(3);
        $originalPaths = array_column($asset->metadata['thumbnails'], 'path');
        $endpoint = "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster";

        $posterPath = $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('custom.jpg', 200, 120)])
            ->assertOk()->json('metadata.thumbnails.0.path');

        $response = $this->deleteJson($endpoint)->assertOk();

        $this->assertSame($originalPaths, array_column($response->json('metadata.thumbnails'), 'path'));
        $this->assertNull($response->json('metadata.generated_thumbnails'));

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $this->assertFalse($disk->exists($posterPath), 'the removed poster should not be orphaned');

        // The restored first frame serves again.
        $this->get($this->posterUrl($asset))->assertOk();
    }

    /**
     * The stash must survive a poster re-upload: only the replaced custom
     * poster's file may be deleted, never the stashed generated frames.
     */
    #[Test]
    public function re_uploading_a_poster_keeps_the_stashed_generated_frames(): void
    {
        $asset = $this->createVideoAsset(2);
        $originalPaths = array_column($asset->metadata['thumbnails'], 'path');
        $endpoint = "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster";

        $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $second = $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('b.jpg')])->assertOk();

        $this->assertSame($originalPaths, array_column($second->json('metadata.generated_thumbnails'), 'path'));

        $disk = app(StorageService::class)->getDefaultStorage($this->space);

        foreach ($originalPaths as $path) {
            $this->assertTrue($disk->exists($path));
        }

        // And a remove after the re-upload still restores the original frames.
        $restored = $this->deleteJson($endpoint)->assertOk();
        $this->assertSame($originalPaths, array_column($restored->json('metadata.thumbnails'), 'path'));
    }

    #[Test]
    public function removing_a_poster_requires_the_manage_ability(): void
    {
        $asset = $this->createDocumentAsset();
        $endpoint = "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster";

        $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('cover.jpg')])->assertOk();

        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $this->deleteJson($endpoint)->assertForbidden();
    }

    /**
     * poster_url must only advertise what the delivery gate will serve: for a
     * non-media asset, `thumbnails` metadata without the custom flag is not a
     * poster.
     */
    #[Test]
    public function it_exposes_no_poster_url_for_a_non_media_asset_without_a_custom_poster(): void
    {
        $asset = $this->createVideoAsset();
        $asset->mime_type = 'application/pdf';
        $asset->save();

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('poster_url', null);
    }

    #[Test]
    public function removing_a_poster_requires_a_custom_one(): void
    {
        $asset = $this->createVideoAsset();

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_custom_poster');
    }

    // --- Posters on arbitrary (non-media) assets ----------------------------

    private function createDocumentAsset(): Asset
    {
        $asset = Asset::create([
            'filename' => 'report',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'storage_id' => $this->storage->id,
            'size' => strlen('pdf-bytes'),
            'metadata' => [],
        ]);

        $asset->path = "{$this->space->id}/{$asset->id}/report.pdf";
        $asset->save();

        app(StorageService::class)->getDefaultStorage($this->space)->put($asset->path, 'pdf-bytes');

        return $asset;
    }

    #[Test]
    public function a_document_asset_accepts_a_custom_poster_and_serves_it(): void
    {
        $asset = $this->createDocumentAsset();

        $thumbnails = $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->image('cover.jpg', 200, 120)],
        )->assertOk()->json('metadata.thumbnails');

        $this->assertCount(1, $thumbnails);
        $this->assertTrue($thumbnails[0]['custom']);

        $response = $this->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/report.pdf/poster");

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('content-type'));
    }

    #[Test]
    public function removing_a_document_poster_leaves_the_asset_without_one(): void
    {
        $asset = $this->createDocumentAsset();
        $endpoint = "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster";

        $posterPath = $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('cover.jpg', 200, 120)])
            ->assertOk()->json('metadata.thumbnails.0.path');

        $response = $this->deleteJson($endpoint)->assertOk();

        $this->assertNull($response->json('metadata.thumbnails'));

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $this->assertFalse($disk->exists($posterPath));

        $this->get("/ilum/{$this->storage->id}/{$this->space->id}/{$asset->id}/report.pdf/poster")
            ->assertNotFound();
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
    public function it_rejects_a_poster_on_an_image_asset(): void
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

    #[Test]
    public function it_rejects_an_svg_poster(): void
    {
        $asset = $this->createVideoAsset();

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => $svg],
        )->assertStatus(422)->assertJsonValidationErrors(['poster']);
    }

    #[Test]
    public function uploading_a_poster_requires_the_manage_ability(): void
    {
        $asset = $this->createVideoAsset();

        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => UploadedFile::fake()->image('custom.jpg')],
        )->assertForbidden();
    }

    /**
     * The stored name carries a random suffix precisely so a re-upload cannot
     * be served from a warm CDN cache under the old URL.
     */
    #[Test]
    public function re_uploading_a_poster_lands_on_a_new_path_and_removes_the_old_one(): void
    {
        $asset = $this->createVideoAsset();
        $endpoint = "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster";

        $first = $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk()->json('metadata.thumbnails.0.path');

        $second = $this->postJson($endpoint, ['poster' => UploadedFile::fake()->image('b.jpg')])
            ->assertOk()->json('metadata.thumbnails.0.path');

        $this->assertNotSame($first, $second);

        $disk = app(StorageService::class)->getDefaultStorage($this->space);
        $this->assertFalse($disk->exists($first), 'the replaced poster should not be orphaned');
        $this->assertTrue($disk->exists($second));

        // And the pinned URL changes with it, so caches cannot collide.
        $this->assertNotSame(
            $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}")->json('poster_url'),
            null,
        );
    }

    /**
     * The stored extension lands in the object key, and on S3 it is what the
     * ContentType gets inferred from, so it has to follow the bytes we
     * detected rather than whatever the client called the file.
     */
    #[Test]
    public function the_stored_poster_extension_follows_the_detected_type_not_the_filename(): void
    {
        $asset = $this->createVideoAsset();

        // Genuine PNG bytes behind a misleading client filename. This needs a
        // real UploadedFile — the fake derives its mime from the name, which
        // is exactly the coupling under test.
        $tmp = tempnam(sys_get_temp_dir(), 'poster');
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $upload = new UploadedFile($tmp, 'payload.html', null, null, true);
        $this->assertSame('image/png', $upload->getMimeType(), 'fixture must sniff as a PNG');

        $path = (string) $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => $upload],
        )->assertOk()->json('metadata.thumbnails.0.path');

        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('.html', $path);
    }

    /**
     * Laravel's own guard, pinned here because the poster endpoint is a file
     * sink and this is the layer beneath our mime allow-list.
     */
    #[Test]
    public function it_blocks_a_php_extension_regardless_of_content(): void
    {
        $asset = $this->createVideoAsset();

        $tmp = tempnam(sys_get_temp_dir(), 'poster');
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/poster",
            ['poster' => new UploadedFile($tmp, 'payload.php', null, null, true)],
        )->assertStatus(422)->assertJsonValidationErrors(['poster']);
    }

    #[Test]
    public function it_exposes_no_poster_url_for_an_asset_without_frames(): void
    {
        $asset = $this->createVideoAsset(0);

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('poster_url', null);
    }

    // --- Transformed responses ----------------------------------------------

    /**
     * A transformed image is generated, not streamed, so it carries an exact
     * ETag and no range support — a repeat request should cost a 304 rather
     * than another transform's worth of bytes.
     */
    #[Test]
    public function a_transformed_poster_revalidates_with_an_exact_entity_tag(): void
    {
        $asset = $this->createVideoAsset();
        $url = $this->posterUrl($asset).'/w_20';

        $first = $this->get($url);
        $first->assertOk();

        $etag = (string) $first->headers->get('etag');
        $this->assertStringStartsWith('"', $etag, 'a generated body gets a strong tag');
        $this->assertNull($first->headers->get('accept-ranges'), 'generated bodies are not seekable');
        $this->assertSame(
            (int) $first->headers->get('content-length'),
            strlen($first->getContent()),
        );

        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function a_transformed_poster_downloads_under_the_output_format(): void
    {
        $asset = $this->createVideoAsset();

        $disposition = (string) $this->get($this->posterUrl($asset).'/w_20?format=webp&download=1')
            ->headers->get('content-disposition');

        $this->assertStringContainsString('.webp', $disposition);
    }

    #[Test]
    public function a_range_request_on_a_transformed_poster_returns_the_whole_image(): void
    {
        $asset = $this->createVideoAsset();

        $this->get($this->posterUrl($asset).'/w_20', ['Range' => 'bytes=0-5'])->assertOk();
    }
}
