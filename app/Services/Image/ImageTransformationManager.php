<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageDriverInterface;
use App\Models\Management\Storage as StorageModel;
use App\Services\Storage\StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Manager;

class ImageTransformationManager extends Manager
{
    public function __construct(
        $app,
        private readonly StorageService $storageService
    ) {
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
        string $storage,
        string $fullPath,
        string $operation,
        array $params,
        ?string $format = null
    ): ?array {
        try {
            if ($storage === 'storage') {
                $disk = Storage::disk();
            } else {
                $storageModel = StorageModel::findOrFail($storage);
                $disk = $this->storageService->getStorage($storageModel);
            }

            if (!$disk->exists($fullPath)) {
                Log::error("Image not found: {$fullPath}");
                return null;
            }

            // Create temporary file for processing
            $tempFile = tempnam(sys_get_temp_dir(), md5($fullPath));
            $stream = $disk->readStream($fullPath);
            if (!$stream) {
                Log::error("Failed to get stream for: {$fullPath}");
                return null;
            }

            file_put_contents($tempFile, stream_get_contents($stream));
            fclose($stream);

            // Load image using current driver
            $driver = $this->driver();
            $image = $driver->loadFromFile($tempFile);

            // Apply the operation
            $processedImage = $this->applyOperation($image, $operation, $params);

            // Determine output format
            $outputFormat = $this->determineOutputFormat($format, $fullPath, $driver);
            $options = $this->getFormatOptions($outputFormat);

            // Convert to buffer
            $buffer = $processedImage->toBuffer($outputFormat, $options);

            // Clean up
            unlink($tempFile);

            return [
                'data' => $buffer,
                'format' => $outputFormat,
                'mime' => config("ilum.formats.{$outputFormat}.mime"),
            ];
        } catch (\Throwable $e) {
            Log::error("Image processing error: {$e->getMessage()}", [
                'storage' => $storage,
                'path' => $fullPath,
                'operation' => $operation,
                'params' => $params,
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
    protected function applyOperation($image, string $operation, array $params)
    {
        return match ($operation) {
            'original' => $image,
            'fit' => $image->fit($params['width'], $params['height']),
            'smartfit' => $image->smartFit($params['width'], $params['height']),
            'fitfocus' => $image->fitFocus(
                $params['x'],
                $params['y'],
                $params['width'],
                $params['height']
            ),
            'resize' => $image->resize($params['width'], $params['height']),
            'crop' => $image->crop(
                $params['x'],
                $params['y'],
                $params['width'],
                $params['height']
            ),
            'cropresize' => $image->cropResize(
                $params['x'],
                $params['y'],
                $params['width'],
                $params['height'],
                $params['targetWidth'],
                $params['targetHeight']
            ),
            default => $image,
        };
    }

    /**
     * Determine the output format
     */
    protected function determineOutputFormat(?string $requestedFormat, string $imagePath, ImageDriverInterface $driver): string
    {
        if ($requestedFormat && in_array($requestedFormat, $driver->getSupportedFormats())) {
            return $requestedFormat;
        }

        return config('ilum.default_format', 'webp');
    }

    /**
     * Get format-specific options
     */
    protected function getFormatOptions(string $format): array
    {
        return config("ilum.formats.{$format}", []);
    }
}
