<?php

namespace App\Services\Storage;

use App\Models\Management\Space;
use App\Models\Management\Storage as StorageModel;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use getID3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetService
{
    public function __construct(
        private readonly StorageService $storageService
    ) {}

    public function storeAsset(Space $space, UploadedFile $file, object $metadata, object $data, ?AssetFolder $folder = null, ?string $externalId = null): Asset
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

            $stream = fopen($file->getRealPath(), 'r');
            $filesystem->writeStream($relativePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (Str::startsWith($mimeType, 'video/')) {
                $thumbnailPaths = $this->generateVideoThumbnails($file, $space->id, $asset->id, $sanitizedFilename, $filesystem);

                if (! empty($thumbnailPaths)) {
                    $asset->metadata = [...$asset->metadata, 'thumbnails' => $thumbnailPaths];
                }
            }

            $asset->save();

            return $asset;
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
     * Generate thumbnails for video at multiple positions
     *
     * @param  string  $spaceId
     * @param  string  $assetId
     * @param  string  $baseName
     * @param  \League\Flysystem\FilesystemOperator  $filesystem
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
                'ffmpeg.binaries' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
                'timeout' => 60,
                'ffmpeg.threads' => 2,
            ]);

            // Create FFProbe instance to get video duration
            $ffprobe = FFProbe::create([
                'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
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

                    // Add the thumbnail path to our result
                    $thumbnailPaths[$index] = [
                        'path' => $relativeThumbnailPath,
                        'position' => $position,
                        'position_formatted' => $this->formatDuration($position),
                    ];

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
        // Use getimagesize to get basic image info
        $imageInfo = getimagesize($file->getRealPath());

        $metadata = [
            'type' => 'image',
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null,
            'aspectRatio' => ($imageInfo[0] && $imageInfo[1])
                ? round($imageInfo[0] / $imageInfo[1], 4)
                : null,
        ];

        // If exif data is available, extract it
        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/tiff'])) {
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
            }
        }

        return $metadata;
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

            foreach (($asset->metadata['thumbnails'] ?? []) as $thumbnail) {
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
     */
    public function replaceFile(Asset $asset, UploadedFile $file, Space $space): Asset
    {
        $storage = StorageModel::findOrFail($asset->storage_id);
        $filesystem = $this->storageService->getStorage($storage);

        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $uniqueFilename = $asset->filename.'_'.Str::random(8);
        $relativePath = "{$space->id}/{$asset->id}/{$uniqueFilename}.{$extension}";

        $stream = fopen($file->getRealPath(), 'r');
        $filesystem->writeStream($relativePath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $newMetadata = array_merge(
            $this->extractMetadata($file, $mimeType),
            ['original_filename' => $originalFilename]
        );

        if (Str::startsWith($mimeType, 'video/')) {
            $thumbnailPaths = $this->generateVideoThumbnails($file, $space->id, $asset->id, $uniqueFilename, $filesystem);
            if (! empty($thumbnailPaths)) {
                $newMetadata['thumbnails'] = $thumbnailPaths;
            }
        }

        $oldPath = $asset->path;
        $oldThumbnails = $asset->metadata['thumbnails'] ?? [];

        // filename (display name) is intentionally preserved
        $asset->extension = $extension;
        $asset->mime_type = $mimeType;
        $asset->size = $file->getSize();
        $asset->path = $relativePath;
        $asset->metadata = $newMetadata;
        $asset->save();

        // Remove old files after successful save
        $filesToDelete = array_filter(array_merge(
            [$oldPath],
            array_column($oldThumbnails, 'path')
        ));

        foreach (array_unique($filesToDelete) as $oldFile) {
            try {
                if ($filesystem->fileExists($oldFile)) {
                    $filesystem->delete($oldFile);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete old file during asset replacement', [
                    'asset' => $asset->id,
                    'path' => $oldFile,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $asset;
    }

    /**
     * Rename an asset file and update its path.
     *
     * @param  string  $newFilename  (without extension)
     */
    public function rename(Asset &$asset, string $newFilename): bool
    {
        try {
            $storage = \App\Models\Management\Storage::findOrFail($asset->storage_id);
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
            \Illuminate\Support\Facades\Log::error('Failed to rename asset', [
                'asset' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
