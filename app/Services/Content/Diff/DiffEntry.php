<?php

namespace App\Services\Content\Diff;

readonly class DiffEntry implements \JsonSerializable
{
    public function __construct(
        public string $path,
        public DiffType $type,
        public mixed $oldValue = null,
        public mixed $newValue = null,
        public ?string $fieldType = null
    ) {}

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'field_type' => $this->fieldType,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
