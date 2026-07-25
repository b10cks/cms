<?php

namespace App\Services\Media;

use App\Models\Space\Asset;
use App\Services\Media\Dto\StoredFile;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Resolves the metadata the delivery layer needs (mime, size, validators)
 * for a stored path.
 *
 * Ordered cheapest-first: the asset row already carries everything for the
 * asset's current file, and S3 can answer the rest with a single HeadObject
 * instead of the three round-trips separate mimeType/size/lastModified calls
 * would cost.
 */
class StoredFileProbe
{
    /**
     * Build a StoredFile from the asset record itself. Only valid when the
     * requested path *is* the asset's current file — versioned paths and
     * thumbnails carry different metadata and must be probed.
     */
    public function fromAsset(Asset $asset, string $path): StoredFile
    {
        return new StoredFile(
            path: $path,
            mime: (string) $asset->mime_type,
            size: $asset->size !== null ? (int) $asset->size : null,
            etag: $asset->checksum ? '"'.$asset->checksum.'"' : null,
            lastModified: $asset->updated_at?->getTimestamp(),
            downloadName: $this->downloadNameFor($asset, $path),
        );
    }

    /**
     * Ask the storage backend about a path we have no database record for.
     */
    public function probe(FilesystemAdapter $disk, string $path): ?StoredFile
    {
        $head = $this->headObject($disk, $path);

        if ($head !== null) {
            return $head;
        }

        if (! $disk->exists($path)) {
            return null;
        }

        $size = $this->intOrNull($disk->size($path));
        $lastModified = $this->intOrNull($disk->lastModified($path));

        return new StoredFile(
            path: $path,
            mime: $disk->mimeType($path) ?: 'application/octet-stream',
            size: $size,
            etag: $this->syntheticEtag($size, $lastModified),
            lastModified: $lastModified,
            downloadName: basename($path),
        );
    }

    /**
     * Without a stored checksum, size + mtime is the same weak validator web
     * servers synthesise for static files — enough to answer a revalidation
     * without transferring the body again.
     */
    private function syntheticEtag(?int $size, ?int $lastModified): ?string
    {
        if ($size === null || $lastModified === null) {
            return null;
        }

        return sprintf('W/"%x-%x"', $lastModified, $size);
    }

    /**
     * Single-round-trip probe for S3-backed disks. Returns null for any other
     * driver (or when the object is missing) so the caller falls back.
     */
    private function headObject(FilesystemAdapter $disk, string $path): ?StoredFile
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        $bucket = $disk->getConfig()['bucket'] ?? null;

        if (! $bucket) {
            return null;
        }

        try {
            $result = $disk->getClient()->headObject([
                'Bucket' => $bucket,
                'Key' => $disk->path($path),
            ]);
        } catch (\Throwable) {
            // A missing key is an expected outcome, not an error worth logging
            // loudly; anything else falls through to the generic probe.
            return null;
        }

        $lastModified = $result->get('LastModified');
        $etag = $result->get('ETag');

        return new StoredFile(
            path: $path,
            mime: (string) ($result->get('ContentType') ?: 'application/octet-stream'),
            size: $this->intOrNull($result->get('ContentLength')),
            // S3 ETags are already quoted and are only weak validators for
            // multipart uploads, but either way they are safe for revalidation.
            etag: is_string($etag) && $etag !== '' ? $etag : null,
            lastModified: $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : null,
            downloadName: basename($path),
        );
    }

    /**
     * Prefer the filename the user actually uploaded over the storage-side
     * name, which carries a uniqueness suffix.
     */
    private function downloadNameFor(Asset $asset, string $path): string
    {
        $original = $asset->metadata['original_filename'] ?? null;

        if (is_string($original) && $original !== '') {
            return basename($original);
        }

        if ($asset->filename && $asset->extension) {
            return $asset->filename.'.'.$asset->extension;
        }

        return basename($path);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
