<?php

namespace App\Contracts\Image;

interface ImageDriverInterface
{
    /**
     * @param  bool  $firstFrameOnly  Decode a multi-frame source as a still of its first frame.
     */
    public function loadFromFile(string $path, bool $firstFrameOnly = false): ImageInterface;

    public function loadFromBuffer($buffer): ImageInterface;

    public function getName(): string;

    public function isAvailable(): bool;

    public function getSupportedFormats(): array;
}
