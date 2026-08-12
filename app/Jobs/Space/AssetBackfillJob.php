<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Management\Storage as StorageModel;
use App\Models\Space\Asset;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing for the ops backfills that walk one space's assets and repair
 * a column or metadata key: the chunked loop, the per-storage filesystem cache,
 * the counters and the cache-based progress percentage (there is no tracking
 * model/UI for these, so progress lives under progressCacheKey() following the
 * same convention as SpaceBackup/SpaceMigration).
 *
 * A subclass supplies the asset query and what to do with a single asset.
 */
abstract class AssetBackfillJob extends QueuedJob
{
    public int $timeout = 3600;

    /**
     * Result counters from the last run, so synchronous callers (the backfill
     * commands with --sync) can report what happened. `skipped` counts assets
     * that need work but could not be updated (unsupported format, missing
     * file, undecodable content) — details are logged per asset at warning
     * level.
     *
     * @var array{total: int, updated: int, failed: int, skipped: int}|null
     */
    public ?array $stats = null;

    /** Assets processed per chunk. */
    private const CHUNK_SIZE = 50;

    public function __construct(
        protected Space $space
    ) {}

    /**
     * Cache-key and queue-tag namespace for this backfill, e.g.
     * `asset-checksum-backfill`.
     */
    abstract protected function name(): string;

    /** Human-readable subject for log messages, e.g. `checksum`. */
    abstract protected function subject(): string;

    /** A fresh builder over the assets this backfill considers. */
    abstract protected function assetQuery(): Builder;

    /**
     * @return 'updated'|'unchanged'|'skipped' `skipped` = the asset needs work
     *                                         but nothing could be applied; diagnostics are logged by the subclass.
     */
    abstract protected function backfillAsset(Asset $asset, Filesystem $filesystem): string;

    protected function execute(): void
    {
        app()->offsetSet('currentSpace', $this->space);

        $this->updateProgress(0);

        $total = $this->assetQuery()->count();

        if ($total === 0) {
            $this->stats = ['total' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
            $this->updateProgress(100);

            return;
        }

        // Assets can live on different storages within one space, so the
        // filesystem is resolved per asset (cached per storage id) instead
        // of assuming the space default.
        $filesystems = [];
        $processed = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        $this->assetQuery()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($assets) use (&$processed, &$updated, &$failed, &$skipped, $total, &$filesystems) {
                foreach ($assets as $asset) {
                    try {
                        $filesystem = $filesystems[$asset->storage_id] ??= app(StorageService::class)
                            ->getStorage(StorageModel::findOrFail($asset->storage_id));

                        match ($this->backfillAsset($asset, $filesystem)) {
                            'updated' => $updated++,
                            'skipped' => $skipped++,
                            default => null,
                        };
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning("Failed to backfill {$this->subject()} for asset", [
                            'space_id' => $this->space->id,
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $processed++;
                    $this->updateProgress((int) min(99, floor($processed / $total * 100)));
                }
            });

        $this->stats = ['total' => $total, 'updated' => $updated, 'failed' => $failed, 'skipped' => $skipped];

        $this->updateProgress(100);

        Log::info("Asset {$this->subject()} backfill finished", [
            'space_id' => $this->space->id,
            ...$this->stats,
        ]);
    }

    protected function updateProgress(int $progress): void
    {
        Cache::put($this->progressCacheKey(), min(100, max(0, $progress)), now()->addHours(6));
    }

    public function progressCacheKey(): string
    {
        return "{$this->name()}:{$this->space->id}:progress";
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error("Failed to backfill asset {$this->subject()}", [
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [
            $this->name(),
            'space:'.$this->space->id,
        ];
    }
}
