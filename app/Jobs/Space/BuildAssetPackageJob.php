<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetPackage;
use App\Services\Asset\AssetPackageService;
use App\Services\Storage\StorageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds a zip package of the assets behind an AssetPackage row: streams each
 * asset file from the space's storage into a local temp dir, zips it (flat,
 * with de-duplicated filenames) and uploads the archive to the transfers disk
 * at `packages/{spaceId}/{packageId}/{filename}.zip` — a key layout the
 * CloudFront download distribution can map 1:1 from `/dl/{spaceId}/...`.
 *
 * Runs on the `heavy` queue (dedicated worker with --timeout=900) since large
 * packages exceed the default worker's 60s timeout.
 */
class BuildAssetPackageJob extends QueuedJob
{
    public $tries = 1;

    public $timeout = 900;

    private string $packageId;

    private string $tempPath;

    /**
     * The package lives in the space database, whose connection can only be
     * resolved once `currentSpace` is bound — so the job carries the id, not
     * the model, and resolves it lazily.
     */
    private ?AssetPackage $package = null;

    public function __construct(
        AssetPackage $package,
        public Space $space,
    ) {
        $this->packageId = $package->id;
        $this->tempPath = storage_path("app/packages/{$this->packageId}");
        $this->onQueue('heavy');
    }

    private function package(): AssetPackage
    {
        // Space model queries (AssetPackage, Asset, AssetFolder, ...) resolve
        // their DB connection from the bound currentSpace; QueuedJob restores
        // any previous binding afterwards.
        app()->offsetSet('currentSpace', $this->space);

        return $this->package ??= AssetPackage::query()->findOrFail($this->packageId);
    }

    protected function execute(): void
    {
        $this->package()->markAsBuilding();

        try {
            $assets = app(AssetPackageService::class)
                ->resolveAssetQueryFor($this->package())
                ->get();

            if ($assets->isEmpty()) {
                throw new \RuntimeException('The package source resolved to no assets.');
            }

            $this->guardPackageSize($assets);

            File::makeDirectory("{$this->tempPath}/files", 0755, true, true);

            $included = $this->collectFiles($assets);

            if ($included === 0) {
                throw new \RuntimeException('None of the package\'s asset files could be read from storage.');
            }

            $zipPath = $this->createZipArchive();
            $s3Path = $this->uploadToS3($zipPath);

            $fileSize = filesize($zipPath);
            $checksum = hash_file('sha256', $zipPath);
            $expiresAt = now()->addDays((int) config('asset_distribution.package_expiry_days', 7));

            $this->package()->markAsCompleted($s3Path, $fileSize, $checksum, $included, $expiresAt);

            $this->cleanup();
        } catch (\Throwable $e) {
            $this->cleanup();
            throw $e;
        }
    }

    /**
     * Refuse to build packages whose source files exceed the configured cap —
     * the build needs roughly twice the total size in local scratch space.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function guardPackageSize(Collection $assets): void
    {
        $maxBytes = (int) config('asset_distribution.max_package_size_mb', 10240) * 1024 * 1024;

        if ($maxBytes <= 0) {
            return;
        }

        $totalBytes = (int) $assets->sum('size');

        if ($totalBytes > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'The package source is too large (%.1f GB); the limit is %.1f GB.',
                $totalBytes / 1024 ** 3,
                $maxBytes / 1024 ** 3,
            ));
        }
    }

    /**
     * Stream every asset file into the temp dir, skipping unreadable/missing
     * files gracefully. Returns the number of files included.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function collectFiles($assets): int
    {
        $filesystem = app(StorageService::class)->getDefaultStorage($this->space);

        $total = $assets->count();
        $included = 0;
        $processed = 0;
        $usedNames = [];

        foreach ($assets as $asset) {
            $processed++;

            try {
                if (! $asset->path) {
                    throw new \RuntimeException('Asset has no stored file path');
                }

                $source = $filesystem->readStream($asset->path);

                if (! is_resource($source)) {
                    throw new \RuntimeException("Unable to read source file: {$asset->path}");
                }

                $entryName = $this->uniqueEntryName($asset, $usedNames);
                $target = fopen("{$this->tempPath}/files/{$entryName}", 'w');

                if ($target === false) {
                    fclose($source);
                    throw new \RuntimeException("Unable to open package target: {$entryName}");
                }

                stream_copy_to_stream($source, $target);
                fclose($target);

                if (is_resource($source)) {
                    fclose($source);
                }

                $included++;
            } catch (\Throwable $e) {
                Log::warning('Skipping asset while building package', [
                    'package_id' => $this->packageId,
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Reserve 80% of the progress bar for file collection.
            $this->package()->updateProgress((int) (($processed / max(1, $total)) * 80));
        }

        return $included;
    }

    /**
     * Flat zip layout; duplicate filenames get " (n)" suffixes.
     *
     * @param  array<string, bool>  $usedNames
     */
    private function uniqueEntryName(Asset $asset, array &$usedNames): string
    {
        $base = trim((string) $asset->filename) !== '' ? $asset->filename : $asset->id;
        $extension = trim((string) $asset->extension);
        $suffix = $extension !== '' ? ".{$extension}" : '';

        $candidate = "{$base}{$suffix}";
        $counter = 1;

        while (isset($usedNames[strtolower($candidate)])) {
            $counter++;
            $candidate = "{$base} ({$counter}){$suffix}";
        }

        $usedNames[strtolower($candidate)] = true;

        return $candidate;
    }

    private function createZipArchive(): string
    {
        $zipPath = storage_path("app/packages/{$this->packageId}.zip");
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create zip archive');
        }

        foreach (File::files("{$this->tempPath}/files") as $file) {
            $zip->addFile($file->getPathname(), $file->getFilename());
        }

        $zip->close();

        $this->package()->updateProgress(90);

        return $zipPath;
    }

    private function uploadToS3(string $zipPath): string
    {
        // Key layout `packages/{spaceId}/{packageId}/{filename}` so the
        // CloudFront /dl/* behavior maps 1:1 (see ShareDeliveryService).
        $filename = ($this->package()->name ? Str::slug($this->package()->name) : 'assets').'.zip';
        $s3Key = "packages/{$this->space->id}/{$this->packageId}/{$filename}";

        $disk = Storage::disk('transfers');
        $stream = fopen($zipPath, 'r');

        try {
            $disk->put($s3Key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $s3Key;
    }

    private function cleanup(): void
    {
        try {
            $zipPath = storage_path("app/packages/{$this->packageId}.zip");

            if (File::exists($zipPath)) {
                File::delete($zipPath);
            }

            if (File::isDirectory($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup package temp files', [
                'package_id' => $this->packageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Asset package build failed', [
            'package_id' => $this->packageId,
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // failed() runs outside handle()'s currentSpace snapshot/restore
        // guard, so restore the ambient binding ourselves.
        $hadSpace = app()->bound('currentSpace');
        $priorSpace = $hadSpace ? app('currentSpace') : null;

        try {
            $this->package()->markAsFailed($e->getMessage());
        } finally {
            if ($hadSpace) {
                app()->offsetSet('currentSpace', $priorSpace);
            } else {
                app()->offsetUnset('currentSpace');
            }
        }

        // Also runs when the worker killed the job (timeout), where execute()'s
        // catch never fired — without this the scratch files would leak.
        $this->cleanup();
    }

    public function tags(): array
    {
        return [
            'asset-package:'.$this->packageId,
            'space:'.$this->space->id,
            'asset-package-build',
        ];
    }
}
