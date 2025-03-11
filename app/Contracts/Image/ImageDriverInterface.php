<?php

namespace App\Contracts\Image;

interface ImageDriverInterface
{
    public function loadFromFile(string $path): ImageInterface;

    public function loadFromBuffer(string $buffer): ImageInterface;

    public function getName(): string;

    public function isAvailable(): bool;

    public function getSupportedFormats(): array;
}
