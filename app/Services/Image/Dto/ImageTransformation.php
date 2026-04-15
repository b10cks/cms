<?php

namespace App\Services\Image\Dto;

readonly class ImageTransformation
{
    /**
     * @param  array<string, int|float|null>  $params
     */
    public function __construct(
        public string $operation,
        public array $params,
        public ?string $format = null,
        public ?int $quality = null,
    ) {}
}
