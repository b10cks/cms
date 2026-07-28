<?php

namespace App\Services\Media\Dto;

use App\Models\Space\Asset;
use Illuminate\Filesystem\FilesystemAdapter;

final readonly class IlumSource
{
    public function __construct(
        public FilesystemAdapter $disk,
        public StoredFile $file,
        public ?Asset $asset,
    ) {}

    public function withFile(StoredFile $file): self
    {
        return new self($this->disk, $file, $this->asset);
    }
}
