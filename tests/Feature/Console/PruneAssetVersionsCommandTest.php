<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PruneAssetVersionsCommand;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(PruneAssetVersionsCommand::class)]
class PruneAssetVersionsCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected Space $space;

    protected Storage $storage;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'driver' => 'local',
            'state' => 'live',
        ]);

        LaravelStorage::fake($this->storage->id);
        $this->setUpSpaceTesting($this->space);

        $this->asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
        ]);
    }

    private function createVersion(int $versionNumber, \DateTimeInterface $createdAt): AssetVersion
    {
        $path = "versions/{$versionNumber}.txt";
        LaravelStorage::disk($this->storage->id)->put($path, "content-{$versionNumber}");

        $version = new AssetVersion;
        $version->asset_id = $this->asset->id;
        $version->version_number = $versionNumber;
        $version->filename = $this->asset->filename;
        $version->extension = 'txt';
        $version->mime_type = 'text/plain';
        $version->path = $path;
        $version->size = 10;
        $version->checksum = hash('sha256', "content-{$versionNumber}");
        $version->metadata = [];
        $version->save();
        $version->created_at = $createdAt;
        $version->save();

        return $version;
    }

    #[Test]
    public function it_prunes_versions_beyond_the_keep_count(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createVersion($i, now());
        }

        $this->artisan('assets:prune-versions', ['--keep' => 2, '--days' => 3650])->assertExitCode(0);

        $remaining = AssetVersion::query()->where('asset_id', $this->asset->id)->orderByDesc('version_number')->pluck('version_number')->all();
        $this->assertSame([5, 4], $remaining);
    }

    #[Test]
    public function it_prunes_versions_older_than_the_retention_days_even_within_the_keep_count(): void
    {
        $this->createVersion(1, now()->subDays(200));
        $this->createVersion(2, now());

        $this->artisan('assets:prune-versions', ['--keep' => 10, '--days' => 90])->assertExitCode(0);

        $remaining = AssetVersion::query()->where('asset_id', $this->asset->id)->pluck('version_number')->all();
        $this->assertSame([2], $remaining);
    }

    #[Test]
    public function it_deletes_the_pruned_versions_physical_file(): void
    {
        $version = $this->createVersion(1, now()->subDays(200));

        $this->artisan('assets:prune-versions', ['--keep' => 10, '--days' => 90])->assertExitCode(0);

        LaravelStorage::disk($this->storage->id)->assertMissing($version->path);
    }

    #[Test]
    public function dry_run_does_not_delete_anything(): void
    {
        $this->createVersion(1, now()->subDays(200));

        $this->artisan('assets:prune-versions', ['--keep' => 10, '--days' => 90, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(1, AssetVersion::query()->where('asset_id', $this->asset->id)->count());
    }
}
