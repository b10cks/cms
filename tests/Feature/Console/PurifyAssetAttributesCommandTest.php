<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PurifyAssetAttributesCommand;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(PurifyAssetAttributesCommand::class)]
class PurifyAssetAttributesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected Asset $asset;

    protected AssetFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $storage = Storage::factory()->create([
            'space_id' => $space->id,
            'driver' => 'local',
            'state' => 'live',
        ]);
        $this->asset = Asset::factory()->create(['storage_id' => $storage->id, 'filename' => 'photo']);
        $this->folder = AssetFolder::factory()->create(['name' => 'Media', 'description' => '<p>Hi</p>']);

        Asset::query()->whereKey($this->asset->id)->toBase()->update(['filename' => 'photo<script>x</script>']);
        AssetFolder::query()->whereKey($this->folder->id)->toBase()->update(['name' => 'Media<script>x</script>']);
    }

    #[Test]
    public function it_purifies_dirty_rows_and_is_a_no_op_on_the_second_run(): void
    {
        $folderUpdatedAt = $this->folder->fresh()->updated_at;

        $this->artisan('assets:purify-attributes')
            ->expectsOutputToContain('2 row(s) updated')
            ->assertExitCode(0);

        $this->assertSame('photo', $this->asset->fresh()->filename);
        $this->assertSame('Media', $this->folder->fresh()->name);
        $this->assertSame('<p>Hi</p>', $this->folder->fresh()->description);
        $this->assertEquals($folderUpdatedAt, $this->folder->fresh()->updated_at);

        $this->artisan('assets:purify-attributes')
            ->expectsOutputToContain('0 row(s) updated')
            ->assertExitCode(0);
    }

    #[Test]
    public function dry_run_reports_counts_without_writing(): void
    {
        $this->artisan('assets:purify-attributes', ['--dry-run' => true])
            ->expectsOutputToContain('would update 1 asset(s), 1 folder(s)')
            ->assertExitCode(0);

        $this->assertSame('photo<script>x</script>', $this->asset->fresh()->filename);
        $this->assertSame('Media<script>x</script>', $this->folder->fresh()->name);
    }
}
