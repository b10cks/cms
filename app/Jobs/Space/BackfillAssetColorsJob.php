<?php

namespace App\Jobs\Space;

use App\Models\Space\Asset;
use App\Services\Asset\DominantColorExtractor;
use App\Services\Storage\AssetService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Backfills `metadata.dominant_color` / `metadata.palette` /
 * `metadata.a11y` (WCAG contrast stats) for image and video assets uploaded
 * before dominant-color extraction existed, across a single space's
 * database. Also repairs assets whose original metadata extraction failed
 * (`extraction_error` in metadata) by re-running the current extraction
 * pipeline against the stored file. Videos use their first generated
 * thumbnail as the color source; assets that already have a color get only
 * the a11y stats topped up (no file read). The chunked walk, progress
 * tracking and counters come from AssetBackfillJob.
 */
class BackfillAssetColorsJob extends AssetBackfillJob
{
    /** Source files larger than this are skipped to bound memory usage. */
    private const MAX_FILE_SIZE = 100 * 1024 * 1024;

    /** Resolved once per run, not once per asset. */
    private ?DominantColorExtractor $extractor = null;

    protected function name(): string
    {
        return 'asset-color-backfill';
    }

    protected function subject(): string
    {
        return 'dominant color';
    }

    protected function assetQuery(): Builder
    {
        return Asset::query()
            ->where(fn ($q) => $q
                ->where('mime_type', 'like', 'image/%')
                ->orWhere('mime_type', 'like', 'video/%'));
    }

    /**
     * @return 'updated'|'unchanged'|'skipped' `skipped` = the asset needs
     *                                         work but nothing could be extracted; diagnostics are logged.
     */
    protected function backfillAsset(Asset $asset, Filesystem $filesystem): string
    {
        $extractor = $this->extractor ??= app(DominantColorExtractor::class);
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
}
