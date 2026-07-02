<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the sha256 `checksum` column for pre-existing assets (uploaded
 * before checksum computation was added) across a single space's database.
 * Progress is tracked in cache under progressCacheKey() since this is an
 * ops backfill with no dedicated tracking model/UI, following the same
 * progress-percentage convention as SpaceBackup/SpaceMigration.
 */
class BackfillAssetChecksumsJob extends QueuedJob
{
    public int $timeout = 3600;

    public function __construct(
        protected Space $space
    ) {}

    protected function execute(): void
    {
        app()->offsetSet('currentSpace', $this->space);

        $this->updateProgress(0);

        $total = Asset::query()->whereNull('checksum')->count();

        if ($total === 0) {
            $this->updateProgress(100);

            return;
        }

        $filesystem = null;
        $processed = 0;
        $updated = 0;
        $failed = 0;

        Asset::query()
            ->whereNull('checksum')
            ->orderBy('id')
            ->chunkById(50, function ($assets) use (&$processed, &$updated, &$failed, $total, &$filesystem) {
                $filesystem ??= app(StorageService::class)->getDefaultStorage($this->space);

                foreach ($assets as $asset) {
                    try {
                        $checksum = $this->computeChecksum($asset, $filesystem);

                        if ($checksum) {
                            $asset->forceFill(['checksum' => $checksum])->saveQuietly();
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('Failed to backfill checksum for asset', [
                            'space_id' => $this->space->id,
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $processed++;
                    $this->updateProgress((int) min(99, floor($processed / $total * 100)));
                }
            });

        $this->updateProgress(100);

        Log::info('Asset checksum backfill finished', [
            'space_id' => $this->space->id,
            'total' => $total,
            'updated' => $updated,
            'failed' => $failed,
        ]);
    }

    private function computeChecksum(Asset $asset, Filesystem $filesystem): ?string
    {
        if (! $asset->path || ! $filesystem->fileExists($asset->path)) {
            return null;
        }

        $stream = $filesystem->readStream($asset->path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');

        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk !== false) {
                hash_update($context, $chunk);
            }
        }

        fclose($stream);

        return hash_final($context);
    }

    protected function updateProgress(int $progress): void
    {
        Cache::put($this->progressCacheKey(), min(100, max(0, $progress)), now()->addHours(6));
    }

    public function progressCacheKey(): string
    {
        return "asset-checksum-backfill:{$this->space->id}:progress";
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to backfill asset checksums', [
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [
            'asset-checksum-backfill',
            'space:'.$this->space->id,
        ];
    }
}
