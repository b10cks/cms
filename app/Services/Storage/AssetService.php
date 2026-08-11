<?php

namespace App\Services\Storage;

use App\Exceptions\DuplicateAssetException;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Management\Storage as StorageModel;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetVersion;
use App\Services\Asset\DominantColorExtractor;
use App\Services\Storage\Filters\HashingStreamFilter;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use getID3;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jcupitt\Vips\Image;
use League\Flysystem\FilesystemOperator;

class AssetService
{
    /**
     * Poster frames are served back through the image pipeline, so they have
     * to be a raster format the driver can decode — SVG is excluded. Doubles
     * as the validation allow-list (see UploadAssetPosterRequest) and as the
     * mime → extension map for the stored path.
     */
    public const array POSTER_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly StorageService $storageService
    ) {}

    public function storeAsset(Space $space, UploadedFile $file, object $metadata, object $data, ?AssetFolder $folder = null, ?string $externalId = null, bool $force = false): Asset
    {
        try {
            $storage = $space->storages()->where('is_default', true)->firstOrFail();
            $filesystem = $this->storageService->getStorage($storage);

            $asset = new Asset;
            $asset->external_id = $externalId;
            $asset->storage_id = $storage->id;
            $asset->data = $data;

            if ($folder) {
                $asset->folder_id = $folder->id;
            }

            $originalFilename = $file->getClientOriginalName();
            $sanitizedFilename = $this->sanitizeFilename($originalFilename);
            $extension = $file->getClientOriginalExtension();
            $mimeType = $file->getMimeType();
            $size = $file->getSize();

            $asset->filename = $sanitizedFilename;
            $asset->extension = $extension;
            $asset->mime_type = $mimeType;
            $asset->size = $size;

            $asset->save();

            $relativePath = "{$space->id}/{$asset->id}/{$sanitizedFilename}.{$extension}";
            $asset->path = $relativePath;

            $extractedMetadata = $this->extractMetadata($file, $mimeType);
            $asset->metadata = array_merge($extractedMetadata, (array) $metadata, [
                'original_filename' => $originalFilename,
            ]);

            // Compute the sha256 checksum as part of the same read that streams
            // the file into storage, so no second pass over the file is needed.
            $asset->checksum = $this->writeStreamWithChecksum($file, $filesystem, $relativePath);

            // Check for duplicates before generating video thumbnails so a
            // rejected duplicate never pays the ffmpeg cost.
            if (! $force) {
                $duplicate = $this->findDuplicateByChecksum($asset->checksum, $asset->id);

                if ($duplicate) {
                    $this->discardUnsavedAsset($asset, $filesystem);

                    throw new DuplicateAssetException($duplicate);
                }
            }

            if (Str::startsWith($mimeType, 'video/')) {
                $thumbnailPaths = $this->generateVideoThumbnails($file, $space->id, $asset->id, $sanitizedFilename, $filesystem);

                if (! empty($thumbnailPaths)) {
                    $asset->metadata = [
                        ...$asset->metadata,
                        'thumbnails' => $thumbnailPaths,
                        ...$this->videoColorMetadata($thumbnailPaths),
                    ];
                }
            }

            $asset->save();

            return $asset;
        } catch (DuplicateAssetException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to store asset', [
                'space' => $space->id,
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Stream a local (temp) file into storage while computing its sha256
     * checksum via a hashing stream filter, so hashing does not require a
     * second read pass over the file.
     */
    private function writeStreamWithChecksum(UploadedFile $file, Filesystem $filesystem, string $relativePath): string
    {
        HashingStreamFilter::register();

        $hashContext = hash_init('sha256');

        $stream = fopen($file->getRealPath(), 'r');
        stream_filter_append($stream, HashingStreamFilter::NAME, STREAM_FILTER_READ, $hashContext);

        $filesystem->writeStream($relativePath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return hash_final($hashContext);
    }

    /**
     * Find another asset in the current space with the same checksum.
     */
    private function findDuplicateByChecksum(?string $checksum, string $excludeAssetId): ?Asset
    {
        if (! $checksum) {
            return null;
        }

        return Asset::query()
            ->where('checksum', $checksum)
            ->where('id', '!=', $excludeAssetId)
            ->first();
    }

    /**
     * Clean up a freshly created asset (row + written files) that turned out
     * to be a duplicate and was not force-uploaded.
     */
    private function discardUnsavedAsset(Asset $asset, Filesystem $filesystem): void
    {
        try {
            if ($asset->path && $filesystem->fileExists($asset->path)) {
                $filesystem->delete($asset->path);
            }

            foreach (($asset->metadata['thumbnails'] ?? []) as $thumbnail) {
                if (! empty($thumbnail['path']) && $filesystem->fileExists($thumbnail['path'])) {
                    $filesystem->delete($thumbnail['path']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to clean up discarded duplicate asset files', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        $asset->forceDelete();
    }

    /**
     * Generate thumbnails for video at multiple positions
     *
     * @param  string  $spaceId
     * @param  string  $assetId
     * @param  string  $baseName
     * @param  FilesystemOperator  $filesystem
     */
    protected function generateVideoThumbnails(UploadedFile $file, $spaceId, $assetId, $baseName, $filesystem): array
    {
        try {
            $thumbnailPaths = [];
            $tempDir = sys_get_temp_dir().'/'.uniqid('video_thumbnails_');

            if (! file_exists($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            // Create FFMpeg instance
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('services.ffmpeg.binary'),
                'ffprobe.binaries' => config('services.ffmpeg.probe'),
                'timeout' => 60,
                'ffmpeg.threads' => 2,
            ]);

            // Create FFProbe instance to get video duration
            $ffprobe = FFProbe::create([
                'ffprobe.binaries' => config('services.ffmpeg.probe'),
                'timeout' => 60,
            ]);

            $video = $ffmpeg->open($file->getRealPath());

            // Get video duration
            $duration = $ffprobe
                ->format($file->getRealPath())
                ->get('duration');

            $duration = (float) $duration;

            // Determine thumbnail positions
            $positions = [
                0, // Start position (0 seconds)
                5, // 5 seconds in
                $duration / 2, // Middle of video
            ];

            // Make sure positions are within video duration
            $positions = array_filter($positions, function ($pos) use ($duration) {
                return $pos < $duration;
            });

            // Make positions unique
            $positions = array_unique($positions);

            // Generate the thumbnails
            foreach ($positions as $index => $position) {
                $thumbnailFilename = "{$baseName}_thumbnail_{$index}.jpg";
                $tempThumbnailPath = "{$tempDir}/{$thumbnailFilename}";

                // Extract frame at the position
                $video->frame(TimeCode::fromSeconds($position))
                    ->save($tempThumbnailPath);

                // Check if thumbnail was generated successfully
                if (file_exists($tempThumbnailPath)) {
                    // Build the path relative to the asset
                    $relativeThumbnailPath = "{$spaceId}/{$assetId}/{$thumbnailFilename}";

                    // Store the thumbnail in the same location as the asset
                    $thumbnailStream = fopen($tempThumbnailPath, 'r');
                    $filesystem->writeStream($relativeThumbnailPath, $thumbnailStream);
                    if (is_resource($thumbnailStream)) {
                        fclose($thumbnailStream);
                    }

                    $thumbnailColors = app(DominantColorExtractor::class)
                        ->extract($tempThumbnailPath, 'image/jpeg');

                    // Add the thumbnail path to our result
                    $thumbnailPaths[$index] = array_filter([
                        'path' => $relativeThumbnailPath,
                        'position' => $position,
                        'position_formatted' => $this->formatDuration($position),
                        'dominant_color' => $thumbnailColors['dominant_color'] ?? null,
                    ], fn ($value) => $value !== null);

                    // Clean up temp file
                    @unlink($tempThumbnailPath);
                }
            }

            // Clean up temp directory
            @rmdir($tempDir);

            return $thumbnailPaths;
        } catch (\Throwable $e) {
            Log::error('Failed to generate video thumbnails', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Replace an asset's thumbnails with a single, hand-picked poster image.
     *
     * `thumbnails` collapses to the custom poster so it never competes with
     * generated frames the editor explicitly rejected — but those frames are
     * stashed under `generated_thumbnails` (files kept) so removing the poster
     * can restore them without re-running ffmpeg. The stored filename carries
     * a random suffix so a re-upload lands on a new path and cannot be served
     * from a warm CDN cache.
     */
    public function setCustomPoster(Asset $asset, UploadedFile $file, Space $space): Asset
    {
        $storage = StorageModel::findOrFail($asset->storage_id);
        $filesystem = $this->storageService->getStorage($storage);

        // The extension comes from the mime type we actually detected, not
        // from the client-supplied filename: it ends up in the stored path,
        // and on S3 it is what the ContentType is inferred from.
        $extension = self::POSTER_EXTENSIONS[(string) $file->getMimeType()] ?? 'jpg';
        $basename = $this->sanitizeFilename((string) $asset->filename) ?: 'asset';
        $relativePath = "{$space->id}/{$asset->id}/{$basename}_poster_".Str::random(8).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'r');

        if (! is_resource($stream)) {
            throw new \RuntimeException('Unable to read the uploaded poster.');
        }

        try {
            $filesystem->writeStream($relativePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $colors = app(DominantColorExtractor::class)
            ->extract($file->getRealPath(), (string) $file->getMimeType());

        $poster = array_filter([
            'path' => $relativePath,
            'position' => 0,
            'position_formatted' => $this->formatDuration(0),
            'dominant_color' => $colors['dominant_color'] ?? null,
            'custom' => true,
        ], fn ($value) => $value !== null);

        $previousThumbnails = array_values(array_filter(
            (array) ($asset->metadata['thumbnails'] ?? []),
            static fn ($thumb): bool => is_array($thumb) && ! empty($thumb['path']),
        ));

        // Generated frames are stashed for a later restore; only a previously
        // uploaded custom poster is actually replaced, so only its file goes.
        $generated = array_values(array_filter($previousThumbnails, static fn (array $thumb): bool => empty($thumb['custom'])));
        $replacedPosters = array_values(array_filter($previousThumbnails, static fn (array $thumb): bool => ! empty($thumb['custom'])));

        $metadata = [
            ...(array) $asset->metadata,
            'thumbnails' => [$poster],
            ...$this->videoColorMetadata([$poster]),
        ];

        if ($generated !== []) {
            $metadata['generated_thumbnails'] = $generated;
        }

        $asset->metadata = $metadata;

        $asset->save();

        $this->deleteThumbnailFiles($replacedPosters, $filesystem);

        return $asset;
    }

    /**
     * Remove a custom poster, restoring the stashed generated frames (if the
     * asset ever had any) as the visible thumbnails.
     */
    public function removeCustomPoster(Asset $asset): Asset
    {
        $storage = StorageModel::findOrFail($asset->storage_id);
        $filesystem = $this->storageService->getStorage($storage);

        $customPosters = array_values(array_filter(
            (array) ($asset->metadata['thumbnails'] ?? []),
            static fn ($thumb): bool => is_array($thumb) && ! empty($thumb['path']) && ! empty($thumb['custom']),
        ));

        $restored = array_values(array_filter(
            (array) ($asset->metadata['generated_thumbnails'] ?? []),
            static fn ($thumb): bool => is_array($thumb) && ! empty($thumb['path']),
        ));

        $metadata = (array) $asset->metadata;
        // The promoted color metadata described the poster; it is recomputed
        // from the restored frames, or dropped when nothing remains.
        unset($metadata['thumbnails'], $metadata['generated_thumbnails'], $metadata['dominant_color'], $metadata['a11y']);

        if ($restored !== []) {
            $metadata['thumbnails'] = $restored;
            $metadata = [...$metadata, ...$this->videoColorMetadata($restored)];
        }

        $asset->metadata = $metadata;
        $asset->save();

        $this->deleteThumbnailFiles($customPosters, $filesystem);

        return $asset;
    }

    /**
     * @param  array<int|string, mixed>  $thumbnails
     */
    private function deleteThumbnailFiles(array $thumbnails, Filesystem|FilesystemOperator $filesystem): void
    {
        foreach (array_unique(array_filter(array_column($thumbnails, 'path'))) as $path) {
            try {
                if ($filesystem->fileExists($path)) {
                    $filesystem->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete replaced thumbnail', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build the delivery URL for an asset's poster frame, pinned to the stored
     * poster path so a poster change produces a new, separately cacheable URL.
     *
     * Mirrors the delivery gate in ImageController::poster() — a URL is only
     * advertised when the endpoint would actually serve it.
     */
    public function getPosterUrl(Asset $asset): ?string
    {
        $mime = (string) $asset->mime_type;

        if (Str::startsWith($mime, 'image/')) {
            return null;
        }

        $thumbnails = array_values(array_filter(
            (array) ($asset->metadata['thumbnails'] ?? []),
            static fn ($thumb): bool => is_array($thumb) && ! empty($thumb['path']),
        ));

        if ($thumbnails === []) {
            return null;
        }

        if (! Str::startsWith($mime, ['video/', 'audio/']) && empty($thumbnails[0]['custom'])) {
            return null;
        }

        $segments = explode('/', (string) $asset->path, 3);

        if (count($segments) !== 3) {
            return null;
        }

        [$spaceId, $assetId, $name] = $segments;

        return route('ilum.poster', [
            'storage' => $asset->storage_id,
            'space' => $spaceId,
            'assetId' => $assetId,
            'name' => $name,
            'v' => substr(sha1((string) $thumbnails[0]['path']), 0, 12),
        ]);
    }

    /**
     * Promote the first video thumbnail's dominant color (plus its WCAG
     * contrast stats) to asset-level metadata so videos get a placeholder
     * color and overlay guidance just like images.
     *
     * @param  array<int|string, array<string, mixed>>  $thumbnailPaths
     * @return array{dominant_color?: string, a11y?: array<string, mixed>}
     */
    protected function videoColorMetadata(array $thumbnailPaths): array
    {
        $first = reset($thumbnailPaths);

        if (! empty($first['dominant_color'])) {
            return [
                'dominant_color' => $first['dominant_color'],
                'a11y' => DominantColorExtractor::a11yStats($first['dominant_color']),
            ];
        }

        return [];
    }

    /**
     * Format duration in seconds to a human-readable string
     */
    protected function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = floor($seconds % 60);

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        } else {
            return sprintf('%02d:%02d', $minutes, $remainingSeconds);
        }
    }

    /**
     * Sanitize a filename to make it safe for storage
     */
    protected function sanitizeFilename(string $filename): string
    {
        $nameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\.]/', '-', $nameWithoutExtension);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = trim($sanitized, '-');
        $sanitized = substr($sanitized, 0, 80);

        if (empty($sanitized)) {
            $sanitized = 'file_'.Str::random(8);
        }

        return $sanitized;
    }

    /**
     * Re-run metadata extraction against an asset's stored file, e.g. to
     * repair assets whose original extraction failed (`extraction_error`
     * in metadata). Returns the freshly extracted metadata, or null when
     * the file is missing/unreadable.
     */
    public function reextractMetadata(Asset $asset, Filesystem $filesystem): ?array
    {
        if (! $asset->path || ! $filesystem->fileExists($asset->path)) {
            return null;
        }

        $extension = pathinfo($asset->path, PATHINFO_EXTENSION);
        $tempFile = tempnam(sys_get_temp_dir(), 'asset_meta_');

        if ($tempFile === false) {
            return null;
        }

        $tempPath = $extension !== '' ? "{$tempFile}.{$extension}" : $tempFile;

        if ($tempPath !== $tempFile) {
            rename($tempFile, $tempPath);
        }

        try {
            $source = $filesystem->readStream($asset->path);

            if (! is_resource($source)) {
                return null;
            }

            $target = fopen($tempPath, 'wb');
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $file = new UploadedFile($tempPath, basename($asset->path), $asset->mime_type, null, true);

            return $this->extractMetadata($file, $file->getMimeType() ?: $asset->mime_type);
        } catch (\Throwable $e) {
            Log::warning('Failed to re-extract asset metadata', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Extract metadata from a file based on its type
     */
    protected function extractMetadata(UploadedFile $file, string $mimeType): array
    {
        $metadata = [];

        try {
            if (Str::startsWith($mimeType, 'image/')) {
                $metadata = $this->extractImageMetadata($file);
            } elseif (Str::startsWith($mimeType, 'video/')) {
                $metadata = $this->extractVideoMetadata($file);
            } elseif (Str::startsWith($mimeType, 'audio/')) {
                $metadata = $this->extractAudioMetadata($file);
            } elseif (Str::startsWith($mimeType, 'application/pdf')) {
                $metadata = $this->extractPdfMetadata($file);
            } else {
                // For other file types, just use basic info
                $metadata = [
                    'type' => 'document',
                    'subtype' => $mimeType,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to extract metadata', [
                'file' => $file->getClientOriginalName(),
                'mime' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            // Return basic information if metadata extraction fails
            $metadata = [
                'type' => $this->getTypeFromMimeType($mimeType),
                'subtype' => $mimeType,
                'extraction_error' => $e->getMessage(),
            ];
        }

        return $metadata;
    }

    /**
     * Extract metadata from an image file
     */
    protected function extractImageMetadata(UploadedFile $file): array
    {
        $mimeType = $file->getMimeType();

        if (in_array($mimeType, ['image/svg+xml', 'image/svg'], true)) {
            return $this->extractSvgMetadata($file);
        }

        // Use getimagesize to get basic image info
        $imageInfo = @getimagesize($file->getRealPath());

        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        // getimagesize can't read every format GD/vips can decode (e.g.
        // HEIC); fall back to a vips header read for the dimensions.
        if (! $width || ! $height) {
            [$width, $height] = $this->probeImageDimensions($file->getRealPath());
        }

        $metadata = [
            'type' => 'image',
        ];

        // If exif data is available, extract it
        if (function_exists('exif_read_data') && in_array($mimeType, ['image/jpeg', 'image/tiff'])) {
            $exif = @exif_read_data($file->getRealPath());
            if ($exif) {
                // Extract only the most important EXIF data
                $metadata['exif'] = [
                    'make' => $exif['Make'] ?? null,
                    'model' => $exif['Model'] ?? null,
                    'exposure' => $exif['ExposureTime'] ?? null,
                    'aperture' => $exif['FNumber'] ?? null,
                    'iso' => $exif['ISOSpeedRatings'] ?? null,
                    'dateTaken' => $exif['DateTimeOriginal'] ?? null,
                    'orientation' => $exif['Orientation'] ?? null,
                ];

                // Orientations 5-8 rotate the image by 90°, so the pixel
                // dimensions are swapped relative to how browsers display it.
                if (($exif['Orientation'] ?? 1) >= 5 && $width && $height) {
                    [$width, $height] = [$height, $width];
                }
            }
        }

        $metadata['width'] = $width;
        $metadata['height'] = $height;
        $metadata['aspectRatio'] = ($width && $height)
            ? round($width / $height, 4)
            : null;

        if ($animated = $this->isAnimatedImage($file, $mimeType)) {
            $metadata['animated'] = $animated;
        }

        $colors = app(DominantColorExtractor::class)->extract($file->getRealPath(), $mimeType);

        if ($colors) {
            $metadata['dominant_color'] = $colors['dominant_color'];
            $metadata['palette'] = $colors['palette'];
            $metadata['a11y'] = $colors['a11y'];
        }

        return $metadata;
    }

    /**
     * Read image dimensions via a lazy libvips header read, for formats
     * getimagesize doesn't understand.
     *
     * @return array{int|null, int|null}
     */
    protected function probeImageDimensions(string $path): array
    {
        try {
            if (class_exists(Image::class)) {
                $image = Image::newFromFile($path);

                return [$image->width, $image->height];
            }
        } catch (\Throwable) {
            // Not decodable by vips either — dimensions stay unknown.
        }

        return [null, null];
    }

    /**
     * Extract dimensions from an SVG's width/height attributes or viewBox.
     * getimagesize can't read SVGs, so without this they'd fall through to
     * the extraction-error branch.
     */
    protected function extractSvgMetadata(UploadedFile $file): array
    {
        $metadata = [
            'type' => 'image',
            'subtype' => 'svg',
        ];

        $contents = @file_get_contents($file->getRealPath(), false, null, 0, 64 * 1024);

        if ($contents === false) {
            return $metadata;
        }

        $width = null;
        $height = null;

        if (preg_match('/<svg[^>]*>/i', $contents, $tag)) {
            if (preg_match('/\bwidth\s*=\s*["\']?([\d.]+)(?:px)?["\']?/i', $tag[0], $w)) {
                $width = (float) $w[1];
            }
            if (preg_match('/\bheight\s*=\s*["\']?([\d.]+)(?:px)?["\']?/i', $tag[0], $h)) {
                $height = (float) $h[1];
            }

            if ((! $width || ! $height)
                && preg_match('/\bviewBox\s*=\s*["\']?\s*[\d.-]+[\s,]+[\d.-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $tag[0], $vb)) {
                $width ??= (float) $vb[1];
                $height ??= (float) $vb[2];
            }
        }

        if ($width && $height) {
            $metadata['width'] = $width;
            $metadata['height'] = $height;
            $metadata['aspectRatio'] = round($width / $height, 4);
        }

        return $metadata;
    }

    /**
     * Detect multi-frame GIFs and WebPs so the UI can badge them and image
     * delivery can preserve animation.
     */
    protected function isAnimatedImage(UploadedFile $file, string $mimeType): bool
    {
        if ($mimeType === 'image/gif') {
            // Count graphic control extension blocks; more than one = animated.
            $contents = @file_get_contents($file->getRealPath(), false, null, 0, 5 * 1024 * 1024);

            return $contents !== false && substr_count($contents, "\x21\xF9\x04") > 1;
        }

        if ($mimeType === 'image/webp') {
            $header = @file_get_contents($file->getRealPath(), false, null, 0, 1024);

            return $header !== false && str_contains($header, 'ANIM');
        }

        return false;
    }

    /**
     * Extract metadata from a video file
     */
    protected function extractVideoMetadata(UploadedFile $file): array
    {
        // Use getId3 for basic video info extraction
        $getId3 = new getID3;
        $fileInfo = $getId3->analyze($file->getRealPath());

        $metadata = [
            'type' => 'video',
            'subtype' => $file->getMimeType(),
            'duration' => isset($fileInfo['playtime_seconds'])
                ? round($fileInfo['playtime_seconds'], 2)
                : null,
            'width' => $fileInfo['video']['resolution_x'] ?? null,
            'height' => $fileInfo['video']['resolution_y'] ?? null,
            'fps' => $fileInfo['video']['frame_rate'] ?? null,
            'bitrate' => $fileInfo['bitrate'] ?? null,
            'codec' => $fileInfo['video']['codec'] ?? null,
        ];

        return $metadata;
    }

    /**
     * Extract metadata from an audio file
     */
    protected function extractAudioMetadata(UploadedFile $file): array
    {
        // Use getId3 for audio info extraction
        $getId3 = new getID3;
        $fileInfo = $getId3->analyze($file->getRealPath());

        $metadata = [
            'type' => 'audio',
            'subtype' => $file->getMimeType(),
            'duration' => isset($fileInfo['playtime_seconds'])
                ? round($fileInfo['playtime_seconds'], 2)
                : null,
            'bitrate' => $fileInfo['audio']['bitrate'] ?? null,
            'channels' => $fileInfo['audio']['channels'] ?? null,
            'sample_rate' => $fileInfo['audio']['sample_rate'] ?? null,
            'codec' => $fileInfo['audio']['codec'] ?? null,
        ];

        // Add tags/ID3 info if available
        if (isset($fileInfo['tags'])) {
            $tags = $fileInfo['tags'];
            $metadata['tags'] = [
                'title' => $tags['title'][0] ?? null,
                'artist' => $tags['artist'][0] ?? null,
                'album' => $tags['album'][0] ?? null,
                'year' => $tags['year'][0] ?? null,
                'genre' => $tags['genre'][0] ?? null,
            ];
        }

        return $metadata;
    }

    /**
     * Extract metadata from a PDF file
     */
    protected function extractPdfMetadata(UploadedFile $file): array
    {
        // Basic PDF metadata extraction
        $metadata = [
            'type' => 'document',
            'subtype' => 'pdf',
        ];

        return $metadata;
    }

    /**
     * Get general file type based on MIME type
     */
    protected function getTypeFromMimeType(string $mimeType): string
    {
        if (Str::startsWith($mimeType, 'image/')) {
            return 'image';
        }

        if (Str::startsWith($mimeType, 'video/')) {
            return 'video';
        }

        if (Str::startsWith($mimeType, 'audio/')) {
            return 'audio';
        }

        if (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
        ])) {
            return 'document';
        }

        return 'file';
    }

    /**
     * Get the URL for an asset
     */
    public function getAssetUrl(Asset $asset): ?string
    {
        try {
            $segments = explode('/', (string) $asset->path, 3);

            if (count($segments) !== 3) {
                throw new \RuntimeException("Unexpected asset path format: {$asset->path}");
            }

            [$spaceId, $assetId, $name] = $segments;

            return route('ilum.original', [
                'storage' => $asset->storage_id,
                'space' => $spaceId,
                'assetId' => $assetId,
                'name' => $name,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to get asset URL', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getThumbnailUrl(Asset $asset)
    {
        return $asset->full_path;
    }

    /**
     * Get the URL for a video thumbnail
     */
    public function getVideoThumbnailUrl(Asset $asset, int $index = 0): ?string
    {
        try {
            // Check if the asset has thumbnails
            if (! isset($asset->metadata['thumbnails']) || empty($asset->metadata['thumbnails'][$index])) {
                return null;
            }

            // Get the storage for this asset
            $storage = StorageModel::findOrFail($asset->storage_id);

            if (! $storage) {
                throw new \Exception("Storage not found for asset: {$asset->id}");
            }

            $filesystem = $this->storageService->getStorage($storage);

            $thumbnailPath = $asset->metadata['thumbnails'][$index]['path'];

            // Check if the thumbnail exists
            if (! $filesystem->exists($thumbnailPath)) {
                return null;
            }

            return $filesystem->url($thumbnailPath);
        } catch (\Throwable $e) {
            Log::error('Failed to get thumbnail URL', [
                'asset' => $asset->id,
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete an asset and all related files
     */
    public function deleteAsset(Asset $asset): bool
    {
        try {
            $storage = StorageModel::findOrFail($asset->storage_id);
            if (! $storage) {
                throw new \Exception("Storage not found for asset: {$asset->id}");
            }

            $filesystem = $this->storageService->getStorage($storage);
            $directoryPath = dirname($asset->path);

            $files = [];
            if ($asset->path) {
                $files[] = $asset->path;
            }

            foreach ([...($asset->metadata['thumbnails'] ?? []), ...($asset->metadata['generated_thumbnails'] ?? [])] as $thumbnail) {
                if (! empty($thumbnail['path'])) {
                    $files[] = $thumbnail['path'];
                }
            }

            if ($directoryPath !== '.' && $directoryPath !== '' && Str::startsWith($directoryPath, $storage->space_id)) {
                $files = [
                    ...$files,
                    ...$filesystem->listContents($directoryPath)
                        ->filter(function ($item) {
                            return method_exists($item, 'isFile')
                                ? $item->isFile()
                                : data_get($item, 'type') === 'file';
                        })
                        ->map(function ($item) {
                            return method_exists($item, 'path')
                                ? $item->path()
                                : data_get($item, 'path');
                        })
                        ->toArray(),
                ];
            }

            foreach (array_values(array_unique(array_filter($files))) as $file) {
                if ($filesystem->fileExists($file)) {
                    $filesystem->delete($file);
                }
            }

            if ($directoryPath !== '.' && $directoryPath !== '' && Str::startsWith($directoryPath, $storage->space_id)) {
                try {
                    $filesystem->deleteDirectory($directoryPath);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete asset directory', [
                        'directory' => $directoryPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $asset->delete();
        } catch (\Throwable $e) {
            Log::error('Failed to delete asset', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Replace the physical file of an existing asset, keeping the same ID and content references.
     * A unique suffix is appended to the filename to bust CDN caches.
     *
     * Before the new file is written, the asset's current file + metadata are
     * snapshotted into a new `asset_versions` row and the old physical file is
     * moved (not deleted) to a versioned path, so it can be restored later.
     */
    public function replaceFile(Asset $asset, UploadedFile $file, Space $space): Asset
    {
        $storage = StorageModel::findOrFail($asset->storage_id);
        $filesystem = $this->storageService->getStorage($storage);

        $this->snapshotVersion($asset, $filesystem, $space);

        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $uniqueFilename = $asset->filename.'_'.Str::random(8);
        $relativePath = "{$space->id}/{$asset->id}/{$uniqueFilename}.{$extension}";

        $checksum = $this->writeStreamWithChecksum($file, $filesystem, $relativePath);

        $newMetadata = array_merge(
            $this->extractMetadata($file, $mimeType),
            ['original_filename' => $originalFilename]
        );

        if (Str::startsWith($mimeType, 'video/')) {
            $thumbnailPaths = $this->generateVideoThumbnails($file, $space->id, $asset->id, $uniqueFilename, $filesystem);
            if (! empty($thumbnailPaths)) {
                $newMetadata['thumbnails'] = $thumbnailPaths;
                $newMetadata = [...$newMetadata, ...$this->videoColorMetadata($thumbnailPaths)];
            }
        }

        $oldThumbnails = [
            ...($asset->metadata['thumbnails'] ?? []),
            ...($asset->metadata['generated_thumbnails'] ?? []),
        ];

        // filename (display name) is intentionally preserved
        $asset->extension = $extension;
        $asset->mime_type = $mimeType;
        $asset->size = $file->getSize();
        $asset->checksum = $checksum;
        $asset->path = $relativePath;
        $asset->metadata = $newMetadata;
        $asset->save();

        // The old main file was already moved into the version's versioned path
        // by snapshotVersion(); only the (unversioned) old thumbnails need cleanup.
        foreach (array_unique(array_filter(array_column($oldThumbnails, 'path'))) as $oldFile) {
            try {
                if ($filesystem->fileExists($oldFile)) {
                    $filesystem->delete($oldFile);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete old thumbnail during asset replacement', [
                    'asset' => $asset->id,
                    'path' => $oldFile,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $asset;
    }

    /**
     * Restore an asset to a previous version's file + metadata. The current
     * state is itself snapshotted first (via snapshotVersion), so restoring
     * is non-destructive and can be undone by restoring again.
     */
    public function restoreVersion(Asset $asset, AssetVersion $version, Space $space): Asset
    {
        if ($version->asset_id !== $asset->id) {
            throw new \InvalidArgumentException('The version does not belong to this asset.');
        }

        if (! $version->path) {
            throw new \RuntimeException("No versioned file is available for asset version: {$version->id}");
        }

        $storage = StorageModel::findOrFail($asset->storage_id);
        $filesystem = $this->storageService->getStorage($storage);

        if (! $filesystem->fileExists($version->path)) {
            throw new \RuntimeException("Versioned file not found in storage for asset version: {$version->id}");
        }

        // Snapshot the state we're about to overwrite, so it isn't lost.
        $this->snapshotVersion($asset, $filesystem, $space);

        $uniqueFilename = $asset->filename.'_'.Str::random(8);
        $relativePath = "{$space->id}/{$asset->id}/{$uniqueFilename}.{$version->extension}";

        // Copy (not move) so the version's file remains available for future restores.
        $filesystem->copy($version->path, $relativePath);

        $asset->extension = $version->extension;
        $asset->mime_type = $version->mime_type;
        $asset->size = $version->size;
        $asset->checksum = $version->checksum;
        $asset->path = $relativePath;
        $asset->metadata = $version->metadata ?? [];
        $asset->save();

        return $asset;
    }

    /**
     * Snapshot the asset's current physical file + metadata into a new,
     * immutable asset_versions row, moving the current file to a versioned
     * path so it is preserved (not overwritten/deleted) by the caller.
     */
    private function snapshotVersion(Asset $asset, Filesystem $filesystem, Space $space): ?AssetVersion
    {
        if (! $asset->path) {
            return null;
        }

        $nextVersionNumber = ((int) AssetVersion::query()->where('asset_id', $asset->id)->max('version_number')) + 1;
        $versionedPath = "{$space->id}/{$asset->id}/versions/{$nextVersionNumber}-".basename($asset->path);

        try {
            if ($filesystem->fileExists($asset->path)) {
                $filesystem->move($asset->path, $versionedPath);
            } else {
                $versionedPath = null;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to move asset file to versioned path', [
                'asset' => $asset->id,
                'path' => $asset->path,
                'error' => $e->getMessage(),
            ]);
            $versionedPath = null;
        }

        $version = new AssetVersion;
        $version->asset_id = $asset->id;
        $version->version_number = $nextVersionNumber;
        $version->filename = $asset->filename;
        $version->extension = $asset->extension;
        $version->mime_type = $asset->mime_type;
        $version->path = $versionedPath;
        $version->size = $asset->size;
        $version->checksum = $asset->checksum;
        $version->metadata = $asset->metadata;
        $version->created_by_id = auth()->id();
        $version->save();

        return $version;
    }

    /**
     * Rename an asset file and update its path.
     *
     * @param  string  $newFilename  (without extension)
     */
    public function rename(Asset &$asset, string $newFilename): bool
    {
        try {
            $storage = Storage::findOrFail($asset->storage_id);
            $filesystem = $this->storageService->getStorage($storage);

            $oldPath = $asset->path;
            $extension = $asset->extension;
            $sanitizedFilename = $this->sanitizeFilename($newFilename);
            $newBasename = $sanitizedFilename.'.'.$extension;
            $newPath = str_replace(
                basename($oldPath),
                $newBasename,
                $oldPath
            );

            if ($filesystem->exists($oldPath)) {
                $filesystem->move($oldPath, $newPath);
                $asset->path = $newPath;
                $asset->filename = $sanitizedFilename;
                $asset->save();

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Failed to rename asset', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
