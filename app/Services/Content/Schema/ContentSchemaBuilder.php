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
                [$node->childTrees, $node->childTreesByRawIndex] = $this->buildChildTrees(
                    $rawValue,
                    $effectiveValue,
                    "{$pathPrefix}.{$key}",
                );
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
     * Raw (submitted) and effective (merged) item lists may be ordered differently —
     * the overlay merger emits effective items in base order regardless of the
     * submission. Items are therefore paired by id, with the positional item as
     * fallback, so each submitted item is validated and pruned against its own
     * block schema.
     *
     * @return array{0: array<int, ContentSchemaTree>, 1: array<int, ContentSchemaTree>}
     */
    protected function buildChildTrees(mixed $rawValue, mixed $effectiveValue, string $pathPrefix): array
    {
        $rawItems = is_array($rawValue) ? array_values($rawValue) : [];
        $effectiveItems = is_array($effectiveValue) ? array_values($effectiveValue) : [];
        $rawIndexById = [];
        $effectiveIds = [];

        foreach ($rawItems as $index => $item) {
            if (($id = $this->itemId($item)) !== null) {
                $rawIndexById[$id] ??= $index;
            }
        }

        foreach ($effectiveItems as $item) {
            if (($id = $this->itemId($item)) !== null) {
                $effectiveIds[$id] = true;
            }
        }

        $childTrees = [];
        $childTreesByRawIndex = [];
        $maxItems = max(count($rawItems), count($effectiveItems));

        for ($index = 0; $index < $maxItems; $index++) {
            $effectiveItem = is_array($effectiveItems[$index] ?? null) ? $effectiveItems[$index] : [];
            $effectiveId = $this->itemId($effectiveItem);

            if ($effectiveId !== null && isset($rawIndexById[$effectiveId])) {
                $rawIndex = $rawIndexById[$effectiveId];
            } else {
                // Positional fallback — but never steal a raw item that belongs to
                // a different effective item by id.
                $positionalId = $this->itemId($rawItems[$index] ?? null);
                $rawIndex = is_array($rawItems[$index] ?? null) && ($positionalId === null || ! isset($effectiveIds[$positionalId]))
                    ? $index
                    : null;
            }

            $rawItem = $rawIndex !== null ? $rawItems[$rawIndex] : [];
            $blockSlug = (string) ($effectiveItem['block'] ?? $rawItem['block'] ?? '');

            if ($blockSlug === '') {
                continue;
            }

            $block = $this->resolveBlock($blockSlug);

            if (! $block) {
                continue;
            }

            $tree = $this->build($block, $rawItem, $effectiveItem, "{$pathPrefix}.{$index}");
            $childTrees[$index] = $tree;

            if ($rawIndex !== null) {
                $childTreesByRawIndex[$rawIndex] = $tree;
            }
        }

        return [$childTrees, $childTreesByRawIndex];
    }

    protected function itemId(mixed $item): ?string
    {
        $id = is_array($item) ? ($item['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function resolveBlock(string $slug): ?Block
    {
        if (array_key_exists($slug, $this->blockCache)) {
            return $this->blockCache[$slug];
        }

        return $this->blockCache[$slug] = Block::query()->where('slug', $slug)->first();
    }
}
