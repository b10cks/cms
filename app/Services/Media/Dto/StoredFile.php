<?php

namespace App\Services\Media\Dto;

/**
 * Everything the delivery layer needs to know about a stored file in order to
 * answer a request without touching the storage backend again.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $mime,
        public ?int $size = null,
        public ?string $etag = null,
        public ?int $lastModified = null,
        public ?string $downloadName = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Range responses are only meaningful when the total length is known, so a
     * probe that could not determine the size falls back to a plain 200.
     */
    public function supportsRanges(): bool
    {
        return $this->size !== null && $this->size > 0;
    }

    public function withDownloadName(?string $name): self
    {
        return new self(
            $this->path,
            $this->mime,
            $this->size,
            $this->etag,
            $this->lastModified,
            $name,
        );
    }
}
