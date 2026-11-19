<?php

namespace App\Contracts\Image;

interface ImageDriverInterface
{
    /**
     * @param  bool  $firstFrameOnly  Decode a multi-frame source as a still of its first frame.
     * @param  int  $shrink  Decode at 1/$shrink of the size (2, 4 or 8) where the format allows it. A driver may ignore it.
     */
    public function loadFromFile(string $path, bool $firstFrameOnly = false, int $shrink = 1): ImageInterface;

    public function loadFromBuffer($buffer): ImageInterface;

    public function getName(): string;

    public function isAvailable(): bool;

    public function getSupportedFormats(): array;
}
