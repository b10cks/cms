<?php

namespace App\Services\Media;

use App\Models\Space\Asset;
use App\Services\Media\Dto\StoredFile;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the metadata the delivery layer needs (mime, size, validators)
 * for a stored path.
 *
 * Size and the validators always come from storage, never from the database:
 * they define the response contract (Content-Length, Content-Range), and a row
 * that has drifted from the object would make us promise bytes we cannot
 * deliver. S3 answers all of it with a single HeadObject instead of the three
 * round-trips separate mimeType/size/lastModified calls would cost.
 */
class StoredFileProbe
{
    /**
     * Probe the asset's current file, describing it with the asset row where
     * the row is the better source.
     *
     * The row wins for mime type and download name — those are descriptive,
     * and the upload-time detection beats what a storage backend infers from
     * the file extension. It never wins for size or the validators; see the
     * class docblock.
     *
     * Only valid when the requested path *is* the asset's current file —
     * versioned paths and thumbnails carry different metadata.
     */
    public function fromAsset(FilesystemAdapter $disk, Asset $asset, string $path): ?StoredFile
    {
        $file = $this->probe($disk, $path);

        if ($file === null) {
            return null;
        }

        return new StoredFile(
            path: $path,
            mime: $asset->mime_type ? (string) $asset->mime_type : $file->mime,
            size: $file->size,
            // The stored checksum is a strong validator and survives rewrites
            // of identical content, but it only describes the bytes the row
            // was written for — fall back to the probed tag once they differ.
            etag: $this->checksumEtag($asset, $file) ?? $file->etag,
            lastModified: $file->lastModified,
            downloadName: $this->downloadNameFor($asset, $path),
        );
    }

    private function checksumEtag(Asset $asset, StoredFile $file): ?string
    {
        $inSync = $asset->checksum
            && $asset->size !== null
            && $file->size !== null
            && (int) $asset->size === $file->size;

        return $inSync ? '"'.$asset->checksum.'"' : null;
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

        try {
            // Deliberately not `exists()`, which is also true for directories
            // — measuring one throws rather than returning a clean miss.
            if (! $disk->fileExists($path)) {
                return null;
            }

            $size = $this->intOrNull($disk->size($path));
            $lastModified = $this->intOrNull($disk->lastModified($path));
            $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        } catch (\Throwable $e) {
            // An unreadable path is a miss, not a 500 on a public endpoint.
            Log::warning('Unable to probe stored file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return new StoredFile(
            path: $path,
            mime: $mime,
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
