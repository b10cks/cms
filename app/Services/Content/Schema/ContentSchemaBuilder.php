<?php

namespace App\Services\Content\Schema;

use App\Models\Space\Block;

class ContentSchemaBuilder
{
    /**
     * @var array<string, Block|null>
     */
    protected array $blockCache = [];

    public function __construct(
        protected readonly ConditionEvaluator $conditionEvaluator,
    ) {}

    public function build(Block $block, array $rawContent, array $effectiveContent, string $pathPrefix = 'content'): ContentSchemaTree
    {
        $schema = $block->schema instanceof BlockSchema
            ? $block->schema
            : BlockSchema::fromArray((array) $block->schema);

        $nodes = [];

        foreach ($schema->getFields() as $key => $field) {
            $rawValue = $rawContent[$key] ?? null;
            $effectiveValue = $effectiveContent[$key] ?? null;
            $node = new ContentSchemaNode(
                field: $field,
                path: "{$pathPrefix}.{$key}",
                localScope: $rawContent,
                effectiveScope: $effectiveContent,
                rawValue: $rawValue,
                effectiveValue: $effectiveValue,
            );
            $node->visible = $this->conditionEvaluator->isVisible(
                $field,
                $schema,
                $rawContent,
                $effectiveContent,
            );

            if ($field->getType() === 'blocks') {
                $node->childTrees = $this->buildChildTrees($rawValue, $effectiveValue, "{$pathPrefix}.{$key}");
            }

            $nodes[$key] = $node;
        }

        return new ContentSchemaTree(
            block: $block,
            schema: $schema,
            pathPrefix: $pathPrefix,
            rawContent: $rawContent,
            effectiveContent: $effectiveContent,
            nodes: $nodes,
        );
    }

    /**
     * @return array<int, ContentSchemaTree>
     */
    protected function buildChildTrees(mixed $rawValue, mixed $effectiveValue, string $pathPrefix): array
    {
        $rawItems = is_array($rawValue) ? array_values($rawValue) : [];
        $effectiveItems = is_array($effectiveValue) ? array_values($effectiveValue) : [];
        $childTrees = [];
        $maxItems = max(count($rawItems), count($effectiveItems));

        for ($index = 0; $index < $maxItems; $index++) {
            $rawItem = is_array($rawItems[$index] ?? null) ? $rawItems[$index] : [];
            $effectiveItem = is_array($effectiveItems[$index] ?? null) ? $effectiveItems[$index] : [];
            $blockSlug = (string) ($effectiveItem['block'] ?? $rawItem['block'] ?? '');

            if ($blockSlug === '') {
                continue;
            }

            $block = $this->resolveBlock($blockSlug);

            if (! $block) {
                continue;
            }

            $childTrees[$index] = $this->build($block, $rawItem, $effectiveItem, "{$pathPrefix}.{$index}");
        }

        return $childTrees;
    }

    protected function resolveBlock(string $slug): ?Block
    {
        if (array_key_exists($slug, $this->blockCache)) {
            return $this->blockCache[$slug];
        }

        return $this->blockCache[$slug] = Block::query()->where('slug', $slug)->first();
    }
}
