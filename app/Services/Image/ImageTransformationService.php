<?php

namespace App\Services\Image;

use App\Models\Management\Storage as StorageModel;
use App\Services\Storage\StorageService;
use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Image as VipsImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageTransformationService
{
    public function __construct(
        private readonly ImageTransformationManager $manager
    ) {
    }

    public function processImage(
        string $storage,
        string $fullPath,
        string $operation,
        array $params,
        ?string $format = null
    ): ?array {
        return $this->manager->processImage($storage, $fullPath, $operation, $params, $format);
    }
}
