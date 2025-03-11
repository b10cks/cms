<?php

namespace App\Services\Content\Diff;

readonly class DiffEntry
{
    public function __construct(
        public string $path,
        public DiffType $type,
        public mixed $oldValue = null,
        public mixed $newValue = null
    ) {}

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
        ];
    }
}
