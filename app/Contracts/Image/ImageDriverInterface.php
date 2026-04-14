<?php

namespace App\Contracts\Image;

interface ImageDriverInterface
{
    public function loadFromFile(string $path): ImageInterface;

    public function loadFromBuffer($buffer): ImageInterface;

    public function getName(): string;

    public function isAvailable(): bool;

    public function getSupportedFormats(): array;
}
