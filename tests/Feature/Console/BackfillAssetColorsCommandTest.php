<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BackfillAssetColorsCommand;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Services\Asset\DominantColorExtractor;
use App\Services\Storage\StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(BackfillAssetColorsCommand::class)]
class BackfillAssetColorsCommandTest extends TestCase
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
                'root' => storage_path('framework/testing/color-backfill-'.$this->space->id),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->filesystem = app(StorageFactory::class)->make($this->storage);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_backfills_dominant_colors_for_images_missing_one_when_run_synchronously(): void
    {
        $path = 'space/asset/legacy.png';
        $this->filesystem->put($path, $this->makeSolidPng(200, 40, 60));

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'mime_type' => 'image/png',
            'metadata' => ['type' => 'image', 'width' => 50, 'height' => 50],
        ]);

        $this->artisan('assets:backfill-colors', ['--sync' => true])->assertExitCode(0);

        $metadata = $asset->fresh()->metadata;

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $metadata['dominant_color']);
        $this->assertNotEmpty($metadata['palette']);
        $this->assertContains($metadata['a11y']['scheme'], ['dark', 'light']);
        // Pre-existing metadata is preserved.
        $this->assertSame(50, $metadata['width']);
    }

    #[Test]
    public function it_skips_assets_that_already_have_color_and_a11y_stats(): void
    {
        $metadata = [
            'dominant_color' => '#123456',
            'a11y' => DominantColorExtractor::a11yStats('#123456'),
        ];

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'space/asset/missing.png',
            'mime_type' => 'image/png',
            'metadata' => $metadata,
        ]);

        $this->artisan('assets:backfill-colors', ['--sync' => true])->assertExitCode(0);

        $this->assertEquals($metadata, $asset->fresh()->metadata);
    }

    #[Test]
    public function it_tops_up_a11y_stats_from_the_stored_color_without_reading_the_file(): void
    {
        // The file intentionally does not exist: the stats derive from the hex.
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'space/asset/missing.png',
            'mime_type' => 'image/png',
            'metadata' => ['dominant_color' => '#111111'],
        ]);

        $this->artisan('assets:backfill-colors', ['--sync' => true])->assertExitCode(0);

        $metadata = $asset->fresh()->metadata;

        $this->assertSame('#111111', $metadata['dominant_color']);
        $this->assertSame('dark', $metadata['a11y']['scheme']);
        $this->assertEquals(DominantColorExtractor::a11yStats('#111111'), $metadata['a11y']);
    }

    #[Test]
    public function it_repairs_svg_assets_with_a_failed_legacy_extraction(): void
    {
        $path = 'space/asset/logo.svg';
        $this->filesystem->put(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"><rect width="320" height="180" fill="red"/></svg>'
        );

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'mime_type' => 'image/svg+xml',
            'metadata' => [
                'type' => 'image',
                'subtype' => 'image/svg+xml',
                'extraction_error' => 'Trying to access array offset on false',
                'alt_text' => 'Custom alt',
            ],
        ]);

        $this->artisan('assets:backfill-colors', ['--sync' => true])->assertExitCode(0);

        $metadata = $asset->fresh()->metadata;

        $this->assertArrayNotHasKey('extraction_error', $metadata);
        $this->assertSame('svg', $metadata['subtype']);
        $this->assertEquals(320, $metadata['width']);
        $this->assertEquals(180, $metadata['height']);
        // Custom metadata supplied at upload survives the repair.
        $this->assertSame('Custom alt', $metadata['alt_text']);
    }

    #[Test]
    public function repairing_a_raster_asset_also_yields_colors_in_one_pass(): void
    {
        $path = 'space/asset/broken-meta.png';
        $this->filesystem->put($path, $this->makeSolidPng(10, 200, 30));

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'mime_type' => 'image/png',
            'metadata' => ['extraction_error' => 'boom'],
        ]);

        $this->artisan('assets:backfill-colors', ['--sync' => true])->assertExitCode(0);

        $metadata = $asset->fresh()->metadata;

        $this->assertArrayNotHasKey('extraction_error', $metadata);
        $this->assertEquals(50, $metadata['width']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $metadata['dominant_color']);
        $this->assertContains($metadata['a11y']['scheme'], ['dark', 'light']);
    }

    #[Test]
    public function repaired_svgs_without_colors_are_complete_not_pending(): void
    {
        // SVGs never get a dominant color; once they carry no extraction
        // error they must not show up as pending in dry-run nor be counted
        // as skipped by the job.
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'space/asset/logo.svg',
            'mime_type' => 'image/svg+xml',
            'metadata' => ['type' => 'image', 'subtype' => 'svg', 'width' => 320, 'height' => 180],
        ]);

        $this->artisan('assets:backfill-colors', ['--dry-run' => true])
            ->doesntExpectOutputToContain('logo.svg')
            ->assertExitCode(0);

        $this->artisan('assets:backfill-colors', ['--sync' => true])
            ->expectsOutputToContain('0 updated, 0 skipped, 0 failed of 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function unrepairable_assets_are_reported_as_skipped(): void
    {
        // File missing from storage: the extraction error can't be repaired
        // and no color can be extracted — the job must surface this instead
        // of silently doing nothing.
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'space/asset/gone.png',
            'mime_type' => 'image/png',
            'metadata' => ['extraction_error' => 'boom'],
        ]);

        $this->artisan('assets:backfill-colors', ['--dry-run' => true])
            ->expectsOutputToContain('failed extraction (boom)')
            ->assertExitCode(0);

        $this->artisan('assets:backfill-colors', ['--sync' => true])
            ->expectsOutputToContain('0 updated, 1 skipped, 0 failed of 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function dry_run_does_not_modify_assets(): void
    {
        $path = 'space/asset/legacy.png';
        $this->filesystem->put($path, $this->makeSolidPng(200, 40, 60));

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'mime_type' => 'image/png',
            'metadata' => ['type' => 'image'],
        ]);

        $this->artisan('assets:backfill-colors', ['--dry-run' => true])->assertExitCode(0);

        $this->assertArrayNotHasKey('dominant_color', $asset->fresh()->metadata);
    }

    private function makeSolidPng(int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor(50, 50);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }
}
