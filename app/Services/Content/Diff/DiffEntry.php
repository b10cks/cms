<?php

namespace App\Services\Content\Diff;

readonly class DiffEntry implements \JsonSerializable
{
    /**
     * @param  DiffEntry[]  $children  per-field sub-diffs of a whole added/removed block item
     */
    public function __construct(
        public string $path,
        public DiffType $type,
        public mixed $oldValue = null,
        public mixed $newValue = null,
        public ?string $fieldType = null,
        public array $children = []
    ) {}

    public function toArray(): array
    {
        $result = [
            'path' => $this->path,
            'type' => $this->type->value,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'field_type' => $this->fieldType,
        ];

        if ($this->children !== []) {
            $result['children'] = array_map(static fn (DiffEntry $child): array => $child->toArray(), $this->children);
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
