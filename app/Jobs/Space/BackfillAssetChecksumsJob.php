<?php

namespace App\Jobs\Space;

use App\Models\Space\Asset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backfills the sha256 `checksum` column for pre-existing assets (uploaded
 * before checksum computation was added) across a single space's database.
 * The chunked walk, progress tracking and counters come from AssetBackfillJob.
 */
class BackfillAssetChecksumsJob extends AssetBackfillJob
{
    protected function name(): string
    {
        return 'asset-checksum-backfill';
    }

    protected function subject(): string
    {
        return 'checksum';
    }

    protected function assetQuery(): Builder
    {
        return Asset::query()->whereNull('checksum');
    }

    protected function backfillAsset(Asset $asset, Filesystem $filesystem): string
    {
        $checksum = $this->computeChecksum($asset, $filesystem);

        if (! $checksum) {
            return 'skipped';
        }

        $asset->forceFill(['checksum' => $checksum])->saveQuietly();

        return 'updated';
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
}
