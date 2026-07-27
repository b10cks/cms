<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageDriverInterface;
use App\Contracts\Image\ImageInterface;
use App\Services\Image\Drivers\ImagickDriver;
use App\Services\Image\Drivers\VipsDriver;
use App\Services\Image\Dto\ImageTransformation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;
use Jcupitt\Vips\Image as VipsImageLib;

class ImageTransformationManager extends Manager
{
    public function __construct($app)
    {
        parent::__construct($app);
    }

    /**
     * Get the default driver name
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('ilum.driver', 'vips');
    }

    /**
     * Create the VIPS driver
     */
    protected function createVipsDriver(): ImageDriverInterface
    {
        return $this->container->make(VipsDriver::class);
    }

    /**
     * Create the ImageMagick driver
     */
    protected function createImagickDriver(): ImageDriverInterface
    {
        return $this->container->make(ImagickDriver::class);
    }

    /**
     * Whether the source image's pixel count is within the configured cap.
     * Returns true when the dimensions can't be read (unknown format) so the
     * driver can still attempt formats getimagesize doesn't understand.
     */
    private function sourceWithinPixelLimit(string $tempFile): bool
    {
        $maxPixels = (int) $this->config->get('ilum.max_source_pixels', 100_000_000);

        if ($maxPixels <= 0) {
            return true;
        }

        $info = @getimagesize($tempFile);

        if ($info === false || ! isset($info[0], $info[1])) {
            return true;
        }

        // getimagesize reports a single frame, but animated sources are decoded
        // with every frame in memory, so a small GIF/WebP with thousands of
        // frames would otherwise walk straight past this cap.
        return ($info[0] * $info[1] * $this->frameCount($tempFile)) <= $maxPixels;
    }

    /**
     * Number of frames in an animated source, or 1 when it is a still image or
     * the count can't be determined.
     */
    private function frameCount(string $tempFile): int
    {
        try {
            if (class_exists(VipsImageLib::class)) {
                return max(1, (int) VipsImageLib::newFromFile($tempFile, ['access' => 'sequential'])->get('n-pages'));
            }

            if (class_exists(\Imagick::class)) {
                return max(1, new \Imagick($tempFile)->getNumberImages());
            }
        } catch (\Throwable) {
            // Unreadable or single-frame format; fall through to the still-image count.
        }

        return 1;
    }

    /**
     * Process an image using the specified operation
     */
    public function processImage(
        FilesystemAdapter $disk,
        string $fullPath,
        ImageTransformation $transformation,
    ): ?array {
        try {
            if (! $disk->exists($fullPath)) {
                Log::error("Image not found: {$fullPath}");

                return null;
            }

            set_time_limit(0);

            $driver = $this->driver();
            $tempFile = $this->copySourceToTemporaryFile($disk, $fullPath);
            if ($tempFile === null) {
                return null;
            }

            // Guard against decompression bombs: a tiny compressed file can
            // declare enormous dimensions that blow up memory when decoded.
            // getimagesize only reads the header, so this is cheap.
            if (! $this->sourceWithinPixelLimit($tempFile)) {
                Log::warning('Rejected oversized source image (possible decompression bomb)', [
                    'path' => $fullPath,
                ]);
                unlink($tempFile);

                return null;
            }

            $image = $driver->loadFromFile($tempFile);
            $outputFormat = $this->determineOutputFormat($transformation->format, $driver, $image);
            $processedImage = $this->applyOperation($image, $transformation);
            $options = $this->getFormatOptions($outputFormat, $transformation->quality);
            $buffer = $processedImage->toBuffer($outputFormat, $options);

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'data' => $buffer,
                'format' => $outputFormat,
                'mime' => $this->config->get("ilum.formats.{$outputFormat}.mime"),
            ];
        } catch (\Throwable $e) {
            Log::error("Image processing error: {$e->getMessage()}", [
                'path' => $fullPath,
                'operation' => $transformation->operation,
                'params' => $transformation->params,
                'driver' => $this->getDefaultDriver(),
            ]);

            // Clean up temp file if it exists
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return null;
        }
    }

    /**
     * Apply the specified operation to the image
     */
    protected function applyOperation($image, ImageTransformation $transformation)
    {
        $params = $transformation->params;

        return match ($transformation->operation) {
            'original' => $image,
            'fit' => $image->fit($params['width'], $params['height']),
            'smartfit' => $image->smartFit($params['width'], $params['height']),
            'fitfocus' => $image->fitFocus($params['focusX'], $params['focusY'], $params['width'], $params['height']),
            'resize' => $image->resize($params['width'], $params['height']),
            'crop' => $image->crop($params['x'], $params['y'], $params['width'], $params['height']),
            'cropresize' => $image->cropResize(
                $params['x'],
                $params['y'],
                $params['width'],
                $params['height'],
                $params['targetWidth'],
                $params['targetHeight'],
            ),
            default => $image,
        };
    }

    /**
     * Determine the output format
     */
    protected function determineOutputFormat(
        ?string $requestedFormat,
        ImageDriverInterface $driver,
        ImageInterface $image,
    ): string {
        if ($requestedFormat && \in_array($requestedFormat, $driver->getSupportedFormats())) {
            return $requestedFormat;
        }

        $defaultFormat = $this->config->get('ilum.default_format', 'webp');

        if ($image->isAnimated() && ! $this->supportsAnimation($defaultFormat)) {
            return 'gif';
        }

        return $defaultFormat;
    }

    /**
     * Get format-specific options
     */
    protected function getFormatOptions(string $format, ?int $quality = null): array
    {
        $options = $this->config->get("ilum.formats.{$format}", []);

        if ($quality !== null) {
            $options['quality'] = $quality;
        }

        return $options;
    }

    protected function supportsAnimation(string $format): bool
    {
        return \in_array($format, ['gif', 'webp'], true);
    }

    protected function copySourceToTemporaryFile(FilesystemAdapter $disk, string $fullPath): ?string
    {
        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
        $tempFile = tempnam(sys_get_temp_dir(), 'ilum_');

        if ($tempFile === false) {
            Log::error("Failed to create temporary file for: {$fullPath}");

            return null;
        }

        $tempPath = $ext !== '' ? "{$tempFile}.{$ext}" : $tempFile;

        if ($tempPath !== $tempFile) {
            rename($tempFile, $tempPath);
        }

        $sourceStream = $disk->readStream($fullPath);
        if (! $sourceStream) {
            Log::error("Failed to get stream for: {$fullPath}");

            return null;
        }

        $tempStream = fopen($tempPath, 'wb');

        if ($tempStream === false) {
            fclose($sourceStream);
            Log::error("Failed to open temporary file for: {$fullPath}");

            return null;
        }

        stream_copy_to_stream($sourceStream, $tempStream);

        fclose($sourceStream);
        fclose($tempStream);

        return $tempPath;
    }
}
