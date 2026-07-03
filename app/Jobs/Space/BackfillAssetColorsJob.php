<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Management\Storage as StorageModel;
use App\Models\Space\Asset;
use App\Services\Asset\DominantColorExtractor;
use App\Services\Storage\AssetService;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Backfills `metadata.dominant_color` / `metadata.palette` /
 * `metadata.a11y` (WCAG contrast stats) for image and video assets uploaded
 * before dominant-color extraction existed, across a single space's
 * database. Also repairs assets whose original metadata extraction failed
 * (`extraction_error` in metadata) by re-running the current extraction
 * pipeline against the stored file. Videos use their first generated
 * thumbnail as the color source; assets that already have a color get only
 * the a11y stats topped up (no file read). Follows the same cache-based
 * progress convention as BackfillAssetChecksumsJob.
 */
class BackfillAssetColorsJob extends QueuedJob
{
    public int $timeout = 3600;

    /**
     * Result counters from the last run, so synchronous callers (the
     * backfill command with --sync) can report what happened. `skipped`
     * counts assets that need work but could not be updated (unsupported
     * format, missing file, undecodable content) — details are logged per
     * asset at warning level.
     *
     * @var array{total: int, updated: int, failed: int, skipped: int}|null
     */
    public ?array $stats = null;

    /** Source files larger than this are skipped to bound memory usage. */
    private const MAX_FILE_SIZE = 100 * 1024 * 1024;

    public function __construct(
        protected Space $space
    ) {}

    protected function execute(): void
    {
        app()->offsetSet('currentSpace', $this->space);

        $this->updateProgress(0);

        $query = fn () => Asset::query()
            ->where(fn ($q) => $q
                ->where('mime_type', 'like', 'image/%')
                ->orWhere('mime_type', 'like', 'video/%'));

        $total = $query()->count();

        if ($total === 0) {
            $this->stats = ['total' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
            $this->updateProgress(100);

            return;
        }

        $extractor = app(DominantColorExtractor::class);
        // Assets can live on different storages within one space, so the
        // filesystem is resolved per asset (cached per storage id) instead
        // of assuming the space default.
        $filesystems = [];
        $processed = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        $query()
            ->orderBy('id')
            ->chunkById(50, function ($assets) use (&$processed, &$updated, &$failed, &$skipped, $total, &$filesystems, $extractor) {
                foreach ($assets as $asset) {
                    try {
                        $filesystem = $filesystems[$asset->storage_id] ??= app(StorageService::class)
                            ->getStorage(StorageModel::findOrFail($asset->storage_id));

                        match ($this->backfillAsset($asset, $filesystem, $extractor)) {
                            'updated' => $updated++,
                            'skipped' => $skipped++,
                            default => null,
                        };
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('Failed to backfill dominant color for asset', [
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

        Log::info('Asset dominant-color backfill finished', [
            'space_id' => $this->space->id,
            'total' => $total,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * @return 'updated'|'unchanged'|'skipped' `skipped` = the asset needs
     *                                         work but nothing could be extracted; diagnostics are logged.
     */
    private function backfillAsset(Asset $asset, Filesystem $filesystem, DominantColorExtractor $extractor): string
    {
        $metadata = $asset->metadata ?? [];
        $changed = false;

        // Repair assets whose original extraction failed (legacy uploads
        // stored the PHP error under `extraction_error`); re-extraction uses
        // the current, fixed extraction pipeline and yields dimensions,
        // colors and a11y stats in one pass. Custom metadata keys supplied
        // at upload are preserved.
        if (isset($metadata['extraction_error'])) {
            $reextracted = app(AssetService::class)->reextractMetadata($asset, $filesystem);

            if ($reextracted !== null && ! isset($reextracted['extraction_error'])) {
                unset($metadata['extraction_error']);
                $metadata = [...$metadata, ...$reextracted];
                $changed = true;
            }
        }

        if (! isset($metadata['dominant_color'])) {
            $colors = str_starts_with((string) $asset->mime_type, 'video/')
                ? $this->extractFromThumbnail($asset, $filesystem, $extractor)
                : $this->extractFromOriginal($asset, $filesystem, $extractor);

            if ($colors) {
                $metadata = [...$metadata, ...$colors];
                $changed = true;
            }
        } elseif (! isset($metadata['a11y'])) {
            // Assets that already have a color only need the a11y stats,
            // which derive from the stored hex — no file read required.
            $metadata['a11y'] = DominantColorExtractor::a11yStats($metadata['dominant_color']);
            $changed = true;
        }

        if ($changed) {
            $asset->forceFill(['metadata' => $metadata])->saveQuietly();

            return 'updated';
        }

        if (! self::needsWork($metadata, $asset->mime_type)) {
            return 'unchanged';
        }

        Log::warning('Asset color/metadata backfill could not update asset', [
            'space_id' => $this->space->id,
            'asset_id' => $asset->id,
            'mime_type' => $asset->mime_type,
            'path' => $asset->path,
            'file_exists' => (bool) ($asset->path && $filesystem->fileExists($asset->path)),
            'has_extraction_error' => isset($metadata['extraction_error']),
            'color_extraction_supported' => DominantColorExtractor::supports($asset->mime_type),
        ]);

        return 'skipped';
    }

    /**
     * Whether an asset's metadata is still missing something this job is
     * responsible for. Formats that never get colors (e.g. SVG) count as
     * complete once they carry no extraction error. Shared with the
     * backfill command so dry-run reporting matches what the job would do.
     */
    public static function needsWork(array $metadata, ?string $mimeType): bool
    {
        if (isset($metadata['extraction_error'])) {
            return true;
        }

        $isVideo = str_starts_with((string) $mimeType, 'video/');

        if (! $isVideo && ! DominantColorExtractor::supports($mimeType)) {
            return false;
        }

        return ! isset($metadata['dominant_color']) || ! isset($metadata['a11y']);
    }

    /**
     * @return array{dominant_color: string, palette: list<string>}|null
     */
    private function extractFromOriginal(Asset $asset, Filesystem $filesystem, DominantColorExtractor $extractor): ?array
    {
        if (! DominantColorExtractor::supports($asset->mime_type)) {
            return null;
        }

        $contents = $this->readFile($asset->path, $filesystem);

        return $contents !== null ? $extractor->extractFromString($contents) : null;
    }

    /**
     * @return array{dominant_color: string, a11y: array<string, mixed>}|null
     */
    private function extractFromThumbnail(Asset $asset, Filesystem $filesystem, DominantColorExtractor $extractor): ?array
    {
        $thumbnails = $asset->metadata['thumbnails'] ?? [];
        $first = reset($thumbnails);

        if (empty($first['path'])) {
            return null;
        }

        $contents = $this->readFile($first['path'], $filesystem);
        $colors = $contents !== null ? $extractor->extractFromString($contents) : null;

        return $colors ? ['dominant_color' => $colors['dominant_color'], 'a11y' => $colors['a11y']] : null;
    }

    private function readFile(?string $path, Filesystem $filesystem): ?string
    {
        if (! $path || ! $filesystem->fileExists($path)) {
            return null;
        }

        if (($filesystem->size($path) ?: 0) > self::MAX_FILE_SIZE) {
            return null;
        }

        $contents = $filesystem->get($path);

        return $contents === false ? null : $contents;
    }

    protected function updateProgress(int $progress): void
    {
        Cache::put($this->progressCacheKey(), min(100, max(0, $progress)), now()->addHours(6));
    }

    public function progressCacheKey(): string
    {
        return "asset-color-backfill:{$this->space->id}:progress";
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to backfill asset dominant colors', [
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [
            'asset-color-backfill',
            'space:'.$this->space->id,
        ];
    }
}
