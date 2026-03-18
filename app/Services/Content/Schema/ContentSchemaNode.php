<?php

namespace App\Services\Content\Schema;

class ContentSchemaNode
{
    /**
     * @param array<int, ContentSchemaTree> $childTrees
     */
    public function __construct(
        public readonly SchemaField $field,
        public readonly string $path,
        public readonly array $localScope,
        public readonly array $effectiveScope,
        public mixed $rawValue,
        public mixed $effectiveValue,
        public bool $visible = true,
        public array $childTrees = [],
    ) {}

    public function dotPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<int, self>
     */
    public function flatten(): array
    {
        $nodes = [$this];

        foreach ($this->childTrees as $childTree) {
            $nodes = [...$nodes, ...$childTree->flatten()];
        }

        return $nodes;
    }
}
