<?php

namespace App\Services\Ai\Assets;

use App\Models\Space\Asset;
use App\Services\Image\ImageTransformationManager;
use App\Services\Storage\StorageService;

/**
 * The image a vision model sees of a library asset: the original when it is
 * small and in a format every provider accepts, otherwise a downscaled WebP
 * rendition (which also covers SVG, TIFF, HEIC and other formats vision APIs
 * reject). Assets that are no images are skipped with a reason.
 */
class AssetVision
{
    public const SKIP_UNSUPPORTED_TYPE = 'unsupported_type';

    public const SKIP_UNREADABLE = 'unreadable';

    /** Originals up to this size in a natively supported format go through untouched. */
    private const MAX_ORIGINAL_BYTES = 2_000_000;

    private const NATIVE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly StorageService $storage,
        private readonly ImageTransformationManager $images,
    ) {}

    /** Why the asset cannot be shown to a vision model, or null when it can. */
    public function skipReason(Asset $asset): ?string
    {
        return \is_string($asset->mime_type) && str_starts_with($asset->mime_type, 'image/')
            ? null
            : self::SKIP_UNSUPPORTED_TYPE;
    }

    /**
     * @return array{mime_type: string, data: string} raw bytes, not base64
     *
     * @throws \RuntimeException when the file cannot be read or converted
     */
    public function image(Asset $asset, int $maxEdge): array
    {
        $asset->loadMissing('storage');
        $disk = $this->storage->getStorage($asset->storage);

        $width = (int) ($asset->metadata['width'] ?? 0);
        $height = (int) ($asset->metadata['height'] ?? 0);
        $fitsNatively = \in_array($asset->mime_type, self::NATIVE_MIME_TYPES, true)
            && $width > 0 && $width <= $maxEdge
            && $height > 0 && $height <= $maxEdge;

        if ($fitsNatively && $disk->size($asset->path) <= self::MAX_ORIGINAL_BYTES) {
            $binary = $disk->get($asset->path);

            if (! \is_string($binary) || $binary === '') {
                throw new \RuntimeException("Asset file {$asset->path} could not be read.");
            }

            if (\strlen($binary) <= self::MAX_ORIGINAL_BYTES) {
                return ['mime_type' => (string) $asset->mime_type, 'data' => $binary];
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'vision-');

        if ($tempFile === false) {
            throw new \RuntimeException('Could not create a temporary file for the image.');
        }

        try {
            $source = $disk->readStream($asset->path);
            $target = fopen($tempFile, 'wb');

            try {
                if (! \is_resource($source) || ! \is_resource($target)) {
                    throw new \RuntimeException("Asset file {$asset->path} could not be read.");
                }

                if (stream_copy_to_stream($source, $target) === false) {
                    throw new \RuntimeException("Asset file {$asset->path} could not be copied.");
                }
            } finally {
                if (\is_resource($source)) {
                    fclose($source);
                }

                if (\is_resource($target)) {
                    fclose($target);
                }
            }

            if ($this->images->exceedsSourcePixelLimit($tempFile)) {
                throw new \RuntimeException('Source image exceeds the configured pixel limit.');
            }

            $image = $this->images->driver()->loadFromFile($tempFile, firstFrameOnly: true);

            if ($image->getWidth() > $maxEdge || $image->getHeight() > $maxEdge) {
                $image = $image->resize($maxEdge, $maxEdge);
            }

            return ['mime_type' => 'image/webp', 'data' => $image->toBuffer('webp', ['quality' => 85])];
        } catch (\Throwable $e) {
            throw new \RuntimeException("Asset {$asset->id} could not be converted for a vision model: {$e->getMessage()}", 0, $e);
        } finally {
            @unlink($tempFile);
        }
    }
}
