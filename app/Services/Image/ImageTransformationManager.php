<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageDriverInterface;
use App\Services\Image\Dto\ImageTransformation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;

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
        return $this->container->make(\App\Services\Image\Drivers\VipsDriver::class);
    }

    /**
     * Create the ImageMagick driver
     */
    protected function createImagickDriver(): ImageDriverInterface
    {
        return $this->container->make(\App\Services\Image\Drivers\ImagickDriver::class);
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
            if (!$disk->exists($fullPath)) {
                Log::error("Image not found: {$fullPath}");
                return null;
            }

            $driver = $this->driver();
            $outputFormat = $this->determineOutputFormat($transformation->format, $driver);
            $tempFile = $this->copySourceToTemporaryFile($disk, $fullPath);
            if ($tempFile === null) {
                return null;
            }

            $image = $driver->loadFromFile($tempFile);
            $processedImage = $this->applyOperation($image, $transformation);
            $options = $this->getFormatOptions($outputFormat, $transformation->quality);
            $buffer = $processedImage->toBuffer($outputFormat, $options);

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'data' => $buffer,
                'format' => $outputFormat,
                'mime' => config("ilum.formats.{$outputFormat}.mime"),
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
    ): string {
        if ($requestedFormat && \in_array($requestedFormat, $driver->getSupportedFormats())) {
            return $requestedFormat;
        }

        return config('ilum.default_format', 'webp');
    }

    /**
     * Get format-specific options
     */
    protected function getFormatOptions(string $format, ?int $quality = null): array
    {
        $options = config("ilum.formats.{$format}", []);

        if ($quality !== null) {
            $options['quality'] = $quality;
        }

        return $options;
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
