<?php

namespace App\Services\Image;

use Illuminate\Filesystem\FilesystemAdapter;

class ImageTransformationService
{
    public function __construct(
        private readonly ImageTransformationManager $manager,
    ) {}

    public function processImage(
        FilesystemAdapter $storage,
        string $fullPath,
        string $operation,
        array $params,
        ?string $format = null,
    ): ?array {
        return $this->manager->processImage($storage, $fullPath, $operation, $params, $format);
    }
}
