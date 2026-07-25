<?php

namespace App\Services\Media;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Media\Dto\IlumSource;
use App\Services\Storage\StorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Turns the `/{storage}/{space}/{assetId}/{name}` URL grammar into a disk, an
 * (optional) asset record and the metadata needed to answer the request.
 *
 * The literal `storage` segment addresses the application's default disk and
 * has no asset record behind it; anything else is a per-space storage id.
 */
class IlumSourceResolver
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly StoredFileProbe $probe,
    ) {}

    public function resolve(string $storage, string $space, string $assetId, string $name): ?IlumSource
    {
        $path = "{$space}/{$assetId}/{$name}";

        return $storage === 'storage'
            ? $this->resolveDefaultDisk($path)
            : $this->resolveSpaceStorage($storage, $space, $assetId, $path);
    }

    private function resolveDefaultDisk(string $path): ?IlumSource
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk();
        $file = $this->probe->probe($disk, $path);

        return $file === null ? null : new IlumSource($disk, $file, null);
    }

    private function resolveSpaceStorage(string $storage, string $space, string $assetId, string $path): ?IlumSource
    {
        $spaceModel = Space::find($space);

        if (! $spaceModel) {
            return null;
        }

        // The space has to be bound before the space-database models resolve.
        app()->offsetSet('currentSpace', $spaceModel);
        request()->route()?->setParameter('space', $spaceModel);

        $storageModel = $spaceModel->storages()->find($storage);

        if (! $storageModel) {
            return null;
        }

        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('storage_id', $storageModel->id)
            ->first();

        if (! $asset) {
            return null;
        }

        /** @var FilesystemAdapter $disk */
        $disk = $this->storageService->getStorage($storageModel);

        // Only the asset's *current* file is described by the asset row;
        // versioned paths and generated thumbnails must be probed.
        $file = $asset->path === $path
            ? $this->probe->fromAsset($asset, $path)
            : $this->probe->probe($disk, $path);

        return $file === null ? null : new IlumSource($disk, $file, $asset);
    }
}
