<?php

namespace App\Services\Image;

use App\Services\Image\Dto\ImageTransformation;
use Illuminate\Filesystem\FilesystemAdapter;

class ImageTransformationService
{
    public function __construct(
        private readonly ImageTransformationManager $manager,
    ) {}

    public function processImage(
        FilesystemAdapter $storage,
        string $fullPath,
        ImageTransformation $transformation,
    ): ?array {
        return $this->manager->processImage($storage, $fullPath, $transformation);
    }
}
