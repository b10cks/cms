<?php

namespace App\Services\Image;

use App\Contracts\Image\ImageDriverInterface;
use App\Models\Management\Storage as StorageModel;
use App\Services\Storage\StorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;

class ImageTransformationManager extends Manager
{
    public function __construct(
        $app,
        private readonly StorageService $storageService,
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
        FilesystemAdapter $disk,
        string $fullPath,
        string $operation,
        array $params,
        ?string $format = null,
    ): ?array {
        try {
            if (!$disk->exists($fullPath)) {
                Log::error("Image not found: {$fullPath}");
                return null;
            }

            // Create temporary file for processing
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            $tempFile = tempnam(sys_get_temp_dir(), md5($fullPath)) . ".$ext";
            $stream = $disk->readStream($fullPath);
            if (!$stream) {
                Log::error("Failed to get stream for: {$fullPath}");
                return null;
            }

            file_put_contents($tempFile, stream_get_contents($stream));
            fclose($stream);

            $driver = $this->driver();
            $image = $driver->loadFromFile($tempFile);

            $processedImage = $this->applyOperation($image, $operation, $params);

            $outputFormat = $this->determineOutputFormat($format, $fullPath, $driver);
            $options = $this->getFormatOptions($outputFormat);

            $buffer = $processedImage->toBuffer($outputFormat, $options);
            unlink($tempFile);

            return [
                'data' => $buffer,
                'format' => $outputFormat,
                'mime' => config("ilum.formats.{$outputFormat}.mime"),
            ];
        } catch (\Throwable $e) {
            Log::error("Image processing error: {$e->getMessage()}", [
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
            'fitfocus' => $image->fitFocus($params['x'], $params['y'], $params['width'], $params['height']),
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
        string $imagePath,
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
    protected function getFormatOptions(string $format): array
    {
        return config("ilum.formats.{$format}", []);
    }
}
