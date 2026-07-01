<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\AssetVersionController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetVersion;
use App\Models\User;
use App\Services\Storage\StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssetVersionController::class)]
class AssetVersionTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected Filesystem $filesystem;

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
                'root' => storage_path('framework/testing/asset-versions-'.$this->space->id),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        // Resolve the same filesystem instance AssetService will resolve
        // (StorageFactory::make() re-registers the disk config on every
        // call, so a plain `Storage::fake()` gets clobbered on first use).
        $this->filesystem = app(StorageFactory::class)->make($this->storage);

        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    private function createAssetWithContent(string $content, string $path = 'space/asset/original.txt'): Asset
    {
        $this->filesystem->put($path, $content);

        return Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
        ]);
    }

    #[Test]
    public function replacing_a_file_creates_a_version_snapshot_and_moves_the_old_file(): void
    {
        $asset = $this->createAssetWithContent('original content', 'space/asset/original.txt');
        $oldPath = $asset->path;

        $newFile = UploadedFile::fake()->createWithContent('new.txt', 'new content');

        $response = $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/replace-file",
            ['file' => $newFile]
        );

        $response->assertOk();

        $this->assertSame(1, AssetVersion::query()->where('asset_id', $asset->id)->count());
        $version = AssetVersion::query()->where('asset_id', $asset->id)->firstOrFail();

        $this->assertSame(1, $version->version_number);
        $this->assertSame(hash('sha256', 'original content'), $version->checksum);
        $this->assertNotNull($version->path);
        $this->assertNotSame($oldPath, $version->path);

        // Old file was moved (not deleted, not left in place).
        $this->assertFalse($this->filesystem->exists($oldPath));
        $this->assertTrue($this->filesystem->exists($version->path));
        $this->assertSame('original content', $this->filesystem->get($version->path));

        // Asset now points at the new file/checksum.
        $asset->refresh();
        $this->assertSame(hash('sha256', 'new content'), $asset->checksum);
        $this->assertTrue($this->filesystem->exists($asset->path));
        $this->assertSame('new content', $this->filesystem->get($asset->path));
    }

    #[Test]
    public function user_can_list_asset_versions(): void
    {
        $asset = $this->createAssetWithContent('v0 content');

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/replace-file",
            ['file' => UploadedFile::fake()->createWithContent('v1.txt', 'v1 content')]
        )->assertOk();

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/replace-file",
            ['file' => UploadedFile::fake()->createWithContent('v2.txt', 'v2 content')]
        )->assertOk();

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/versions");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        // Most recent first.
        $this->assertSame(2, $response->json('data.0.version_number'));
        $this->assertSame(1, $response->json('data.1.version_number'));
    }

    #[Test]
    public function restoring_a_version_reverts_the_file_and_snapshots_the_pre_restore_state(): void
    {
        $asset = $this->createAssetWithContent('original content');

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/replace-file",
            ['file' => UploadedFile::fake()->createWithContent('new.txt', 'replaced content')]
        )->assertOk();

        $originalVersion = AssetVersion::query()->where('asset_id', $asset->id)->where('version_number', 1)->firstOrFail();

        $response = $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}/versions/{$originalVersion->id}/restore"
        );

        $response->assertOk();

        $asset->refresh();
        $this->assertSame(hash('sha256', 'original content'), $asset->checksum);
        $this->assertTrue($this->filesystem->exists($asset->path));
        $this->assertSame('original content', $this->filesystem->get($asset->path));

        // The restore itself created a new snapshot of the pre-restore ("replaced content") state.
        $this->assertSame(2, AssetVersion::query()->where('asset_id', $asset->id)->count());
        $preRestoreSnapshot = AssetVersion::query()->where('asset_id', $asset->id)->where('version_number', 2)->firstOrFail();
        $this->assertSame(hash('sha256', 'replaced content'), $preRestoreSnapshot->checksum);

        // The restored-from version row remains untouched (immutable history).
        $originalVersion->refresh();
        $this->assertTrue($this->filesystem->exists($originalVersion->path));
    }

    #[Test]
    public function restoring_a_version_that_does_not_belong_to_the_asset_is_rejected(): void
    {
        $assetA = $this->createAssetWithContent('a content', 'space/asset/a.txt');
        $assetB = $this->createAssetWithContent('b content', 'space/asset/b.txt');

        $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$assetA->id}/replace-file",
            ['file' => UploadedFile::fake()->createWithContent('a2.txt', 'a2 content')]
        )->assertOk();

        $versionOfA = AssetVersion::query()->where('asset_id', $assetA->id)->firstOrFail();

        $response = $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/assets/{$assetB->id}/versions/{$versionOfA->id}/restore"
        );

        $response->assertStatus(404);
    }
}
