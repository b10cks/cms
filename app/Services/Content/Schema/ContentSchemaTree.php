<?php

namespace App\Services\Content\Schema;

use App\Models\Space\Block;

class ContentSchemaTree
{
    /**
     * @param array<string, ContentSchemaNode> $nodes
     */
    public function __construct(
        public readonly Block $block,
        public readonly BlockSchema $schema,
        public readonly string $pathPrefix,
        public readonly array $rawContent,
        public readonly array $effectiveContent,
        public array $nodes = [],
    ) {}

    /**
     * @return array<int, ContentSchemaNode>
     */
    public function flatten(): array
    {
        $nodes = [];

        foreach ($this->nodes as $node) {
            $nodes = [...$nodes, ...$node->flatten()];
        }

        return $nodes;
    }
}
