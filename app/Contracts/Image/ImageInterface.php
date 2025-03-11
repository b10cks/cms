<?php

namespace App\Contracts\Image;

interface ImageInterface
{
    public function getWidth(): int;

    public function getHeight(): int;

    public function resize(int $width, int $height): self;

    public function crop(int $x, int $y, int $width, int $height): self;

    public function fit(int $width, int $height): self;

    public function smartFit(int $width, int $height): self;

    public function fitFocus(int $focusX, int $focusY, int $width, int $height): self;

    public function cropResize(int $x, int $y, int $width, int $height, int $targetWidth, int $targetHeight): self;

    public function toBuffer(string $format, array $options = []): string;

    public function getResource(): mixed;
}
