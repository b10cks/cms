<?php

namespace Tests\Feature\Jobs\Space;

use App\Jobs\Space\AssetBackfillJob;
use App\Jobs\Space\BackfillAssetChecksumsJob;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Services\Storage\StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The plumbing every per-space asset backfill shares: the chunked walk, the
 * counters, and the cache-based progress percentage. Exercised through the
 * checksum backfill (a real subclass) plus a recording subclass that reports
 * the progress it sees while the run is still in flight.
 */
#[CoversClass(AssetBackfillJob::class)]
class AssetBackfillJobTest extends TestCase
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
                'root' => storage_path('framework/testing/asset-backfill-'.$this->space->id),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->filesystem = app(StorageFactory::class)->make($this->storage);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_completes_immediately_when_there_is_nothing_to_do(): void
    {
        $job = new BackfillAssetChecksumsJob($this->space);
        $job->handle();

        $this->assertSame(['total' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0], $job->stats);
        $this->assertSame(100, Cache::get($job->progressCacheKey()));
    }

    #[Test]
    public function it_advances_progress_per_asset_and_only_reaches_100_at_the_end(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->asset("space/asset/{$name}.txt", $name);
        }

        $job = new RecordingAssetBackfillJob($this->space);
        $job->handle();

        // The value each asset saw on entry: 0 before the first, then
        // floor(processed / total * 100) after each one.
        $this->assertSame([0, 33, 66], $job->seenProgress);
        $this->assertSame(100, Cache::get($job->progressCacheKey()));
    }

    #[Test]
    public function it_caps_in_flight_progress_at_99(): void
    {
        $this->asset('space/asset/only.txt', 'only');

        $job = new RecordingAssetBackfillJob($this->space);
        $job->handle();

        $this->assertLessThanOrEqual(99, $job->lastWrittenProgress());
    }

    #[Test]
    public function it_counts_updated_skipped_and_failed_assets(): void
    {
        $this->asset('space/asset/present.txt', 'present');
        // Missing file: the handler can produce nothing, so it is skipped.
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'space/asset/gone.txt',
            'checksum' => null,
        ]);
        // Unknown storage: resolving the filesystem throws, so it is failed.
        $this->orphan('space/asset/orphan.txt');

        $job = new BackfillAssetChecksumsJob($this->space);
        $job->handle();

        $this->assertSame(
            ['total' => 3, 'updated' => 1, 'failed' => 1, 'skipped' => 1],
            $job->stats
        );
        $this->assertSame(100, Cache::get($job->progressCacheKey()));
    }

    #[Test]
    public function a_failing_asset_does_not_stop_the_run(): void
    {
        $this->orphan('space/asset/orphan.txt');
        $asset = $this->asset('space/asset/present.txt', 'present');

        (new BackfillAssetChecksumsJob($this->space))->handle();

        $this->assertSame(hash('sha256', 'present'), $asset->fresh()->checksum);
    }

    #[Test]
    public function progress_key_and_tags_are_namespaced_per_backfill_and_space(): void
    {
        $job = new BackfillAssetChecksumsJob($this->space);

        $this->assertSame("asset-checksum-backfill:{$this->space->id}:progress", $job->progressCacheKey());
        $this->assertSame(['asset-checksum-backfill', 'space:'.$this->space->id], $job->tags());
    }

    #[Test]
    public function a_terminal_failure_is_logged_with_the_space(): void
    {
        Log::spy();

        (new BackfillAssetChecksumsJob($this->space))->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'checksum')
                && $context['space_id'] === $this->space->id
                && $context['error'] === 'boom'
        );
    }

    /**
     * An asset pointing at a storage row that no longer exists, so resolving
     * its filesystem throws. The storage is detached after creation because the
     * factory dereferences it while rewriting the path.
     */
    private function orphan(string $path): Asset
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'checksum' => null,
        ]);

        $asset->forceFill(['storage_id' => 'does-not-exist'])->saveQuietly();

        return $asset;
    }

    private function asset(string $path, string $contents): Asset
    {
        $this->filesystem->put($path, $contents);

        return Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => $path,
            'checksum' => null,
        ]);
    }
}

/**
 * Records the progress percentage visible at the start of each asset, so the
 * base class's progress math can be asserted from outside the run.
 */
class RecordingAssetBackfillJob extends AssetBackfillJob
{
    /** @var list<int|null> */
    public array $seenProgress = [];

    private ?int $lastWritten = null;

    public function lastWrittenProgress(): ?int
    {
        return $this->lastWritten;
    }

    protected function name(): string
    {
        return 'test-asset-backfill';
    }

    protected function subject(): string
    {
        return 'test value';
    }

    protected function assetQuery(): Builder
    {
        return Asset::query();
    }

    protected function backfillAsset(Asset $asset, Filesystem $filesystem): string
    {
        $this->seenProgress[] = Cache::get($this->progressCacheKey());

        return 'updated';
    }

    protected function updateProgress(int $progress): void
    {
        // The final 100 is written after the loop; keep the last in-flight one.
        if ($progress !== 100) {
            $this->lastWritten = $progress;
        }

        parent::updateProgress($progress);
    }
}
