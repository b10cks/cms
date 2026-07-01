<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BackfillAssetChecksumsCommand;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Services\Storage\StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(BackfillAssetChecksumsCommand::class)]
class BackfillAssetChecksumsCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected Space $space;

    protected Storage $storage;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => [
                'root' => storage_path('framework/testing/checksum-backfill-'.$this->space->id),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        // Resolve the same filesystem instance the job/command will resolve
        // (StorageFactory::make() re-registers the disk config on every call,
        // so a plain `Storage::fake()` gets clobbered on first real use).
        $this->filesystem = app(StorageFactory::class)->make($this->storage);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_backfills_checksums_for_assets_missing_one_when_run_synchronously(): void
    {
        $path = 'space/asset/legacy.txt';
        $this->filesystem->put($path, 'legacy content');

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'checksum' => null,
        ]);

        $this->artisan('assets:backfill-checksums', ['--sync' => true])->assertExitCode(0);

        $this->assertSame(hash('sha256', 'legacy content'), $asset->fresh()->checksum);
    }

    #[Test]
    public function dry_run_does_not_modify_assets(): void
    {
        $path = 'space/asset/legacy.txt';
        $this->filesystem->put($path, 'legacy content');

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'checksum' => null,
        ]);

        $this->artisan('assets:backfill-checksums', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($asset->fresh()->checksum);
    }
}
