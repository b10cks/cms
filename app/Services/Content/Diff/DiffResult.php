<?php

namespace App\Services\Content\Diff;

readonly class DiffResult implements \JsonSerializable
{
    public function __construct(
        public array $entries
    )
    {
    }

    public function toArray(): array
    {
        return array_map(fn ($entry) => $entry->toArray(), $this->entries);
    }

    public function jsonSerialize(): array
    {
        return ['entries' => $this->entries];
    }

    public function hasChanges(): bool
    {
        return !empty($this->entries);
    }

    public function getChangesByType(DiffType $type): array
    {
        return array_filter($this->entries, fn ($entry) => $entry->type === $type);
    }
}
