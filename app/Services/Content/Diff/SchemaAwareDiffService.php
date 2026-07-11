<?php

namespace App\Services\Content\Diff;

use App\Services\Content\Schema\SchemaField;
use Illuminate\Support\Collection;

/**
 * Diffs two content arrays at field boundaries using the block schema.
 *
 * Fields with a known type diff as a single unit, so a changed link or
 * asset surfaces as one entry annotated with its field type instead of
 * being flattened into per-key noise. `blocks` fields recurse, aligning
 * nested block items by their stable `id` so inserting an item in the
 * middle doesn't mark every following item as changed. Unknown structures
 * fall back to the legacy path flattening.
 */
class SchemaAwareDiffService
{
    private ArrayDiffService $fallback;
    private ValueComparer $valueComparer;

    public function __construct(
        ?ArrayDiffService $fallback = null,
        ?ValueComparer $valueComparer = null
    ) {
        $this->fallback = $fallback ?? new ArrayDiffService();
        $this->valueComparer = $valueComparer ?? new ValueComparer();
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema  field key => attributes (with 'type')
     * @param  callable(string): array  $blockSchemaResolver  block slug => schema array
     */
    public function diff(array $old, array $new, array $schema, callable $blockSchemaResolver): DiffResult
    {
        $entries = collect();
        $this->diffFields($old, $new, $schema, '', $entries, $blockSchemaResolver);

        return new DiffResult(
            $entries
                ->sort(static fn (DiffEntry $a, DiffEntry $b): int => strnatcmp($a->path, $b->path))
                ->values()
                ->toArray()
        );
    }

    private function diffFields(array $old, array $new, array $schema, string $prefix, Collection $entries, callable $resolver): void
    {
        foreach (array_keys($old + $new) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $fieldType = isset($schema[$key]['type'])
                ? SchemaField::canonicalizeType((string) $schema[$key]['type'])
                : null;

            $oldValue = array_key_exists($key, $old) ? $old[$key] : null;
            $newValue = array_key_exists($key, $new) ? $new[$key] : null;

            // Blocks fields always diff per item — including whole-field
            // additions/removals — so each item stays reviewable instead
            // of collapsing into one array-sized entry.
            if ($fieldType === 'blocks' && \is_array($oldValue ?? []) && \is_array($newValue ?? [])) {
                $this->diffBlockItems($oldValue ?? [], $newValue ?? [], $path, $entries, $resolver);
                continue;
            }

            if (! array_key_exists($key, $new)) {
                $entries->push(new DiffEntry($path, DiffType::REMOVED, $oldValue, null, $fieldType));
                continue;
            }

            if (! array_key_exists($key, $old)) {
                $entries->push(new DiffEntry($path, DiffType::ADDED, null, $newValue, $fieldType));
                continue;
            }

            if ($this->valueComparer->areEqual($oldValue, $newValue)) {
                continue;
            }

            if ($fieldType === null && \is_array($oldValue) && \is_array($newValue)) {
                if (ArrayDiffService::isRichTextDoc($oldValue) && ArrayDiffService::isRichTextDoc($newValue)) {
                    $fieldType = 'richtext';
                } else {
                    $this->pushFallbackEntries($oldValue, $newValue, $path, $entries);
                    continue;
                }
            }

            $entries->push(new DiffEntry($path, DiffType::CHANGED, $oldValue, $newValue, $fieldType));
        }
    }

    private function diffBlockItems(array $old, array $new, string $path, Collection $entries, callable $resolver): void
    {
        $oldById = $this->keyItemsById($old);
        $newById = $this->keyItemsById($new);

        if ($oldById === null || $newById === null) {
            $this->diffBlockItemsPositionally($old, $new, $path, $entries, $resolver);

            return;
        }

        foreach ($oldById as $id => [$oldIndex, $oldItem]) {
            if (! isset($newById[$id])) {
                $entries->push($this->blockItemEntry($path . '.' . $oldIndex, DiffType::REMOVED, $oldItem, null, $resolver));
            }
        }

        foreach ($newById as $id => [$newIndex, $newItem]) {
            if (! isset($oldById[$id])) {
                $entries->push($this->blockItemEntry($path . '.' . $newIndex, DiffType::ADDED, null, $newItem, $resolver));
                continue;
            }

            [, $oldItem] = $oldById[$id];

            if ($this->valueComparer->areEqual($oldItem, $newItem)) {
                continue;
            }

            $slug = (string) ($newItem['block'] ?? '');

            if ($slug !== (string) ($oldItem['block'] ?? '')) {
                $entries->push($this->blockItemEntry($path . '.' . $newIndex, DiffType::CHANGED, $oldItem, $newItem, $resolver));
                continue;
            }

            $this->diffFields($oldItem, $newItem, $resolver($slug), $path . '.' . $newIndex, $entries, $resolver);
        }
    }

    /**
     * Items without stable unique ids pair by position. Field-level
     * rendering stays schema-aware; insertions shift the pairing, which
     * is the best available alignment without identity.
     */
    private function diffBlockItemsPositionally(array $old, array $new, string $path, Collection $entries, callable $resolver): void
    {
        $old = array_values($old);
        $new = array_values($new);

        for ($index = 0, $count = max(\count($old), \count($new)); $index < $count; $index++) {
            $itemPath = $path . '.' . $index;
            $oldItem = $old[$index] ?? null;
            $newItem = $new[$index] ?? null;

            if ($newItem === null) {
                $entries->push($this->blockItemEntry($itemPath, DiffType::REMOVED, $oldItem, null, $resolver));
                continue;
            }

            if ($oldItem === null) {
                $entries->push($this->blockItemEntry($itemPath, DiffType::ADDED, null, $newItem, $resolver));
                continue;
            }

            if ($this->valueComparer->areEqual($oldItem, $newItem)) {
                continue;
            }

            $slug = \is_array($newItem) ? (string) ($newItem['block'] ?? '') : '';

            if (! \is_array($oldItem) || ! \is_array($newItem) || $slug !== (string) ($oldItem['block'] ?? '')) {
                $entries->push($this->blockItemEntry($itemPath, DiffType::CHANGED, $oldItem, $newItem, $resolver));
                continue;
            }

            $this->diffFields($oldItem, $newItem, $resolver($slug), $itemPath, $entries, $resolver);
        }
    }

    /**
     * Builds a whole-item block entry whose children hold per-field
     * sub-diffs, so the UI renders each field with its own type-aware
     * diff instead of dumping the item as JSON. When children carry the
     * field data the item itself is slimmed to its identity keys —
     * otherwise nested blocks multiply the payload per nesting level.
     */
    private function blockItemEntry(string $path, DiffType $type, mixed $oldItem, mixed $newItem, callable $resolver): DiffEntry
    {
        $children = $this->blockItemChildren($oldItem, $newItem, $resolver);

        if ($children !== []) {
            $oldItem = $this->slimItem($oldItem);
            $newItem = $this->slimItem($newItem);
        }

        return new DiffEntry($path, $type, $oldItem, $newItem, 'block', $children);
    }

    /**
     * @return DiffEntry[]
     */
    private function blockItemChildren(mixed $oldItem, mixed $newItem, callable $resolver): array
    {
        $oldItem = \is_array($oldItem) ? $oldItem : null;
        $newItem = \is_array($newItem) ? $newItem : null;

        $children = collect();

        if ($oldItem !== null && $newItem !== null) {
            // Slug change: cross-diff under the union schema (new wins) so
            // fields keeping their value don't read as removed + re-added.
            $schema = array_replace(
                $resolver((string) ($oldItem['block'] ?? '')),
                $resolver((string) ($newItem['block'] ?? ''))
            );
            $this->diffFields($oldItem, $newItem, $schema, '', $children, $resolver);
        } elseif ($oldItem !== null) {
            $this->diffFields($oldItem, [], $resolver((string) ($oldItem['block'] ?? '')), '', $children, $resolver);
        } elseif ($newItem !== null) {
            $this->diffFields([], $newItem, $resolver((string) ($newItem['block'] ?? '')), '', $children, $resolver);
        }

        return $children
            ->reject(static fn (DiffEntry $entry): bool => \in_array($entry->path, ['id', 'block'], true))
            ->sort(static fn (DiffEntry $a, DiffEntry $b): int => strnatcmp($a->path, $b->path))
            ->values()
            ->all();
    }

    private function slimItem(mixed $item): mixed
    {
        return \is_array($item)
            ? array_intersect_key($item, ['id' => true, 'block' => true])
            : $item;
    }

    /**
     * @return array<string, array{0: int, 1: array}>|null  id => [index, item], or null when
     *                                                       items lack unique stable ids
     */
    private function keyItemsById(array $items): ?array
    {
        $result = [];

        foreach (array_values($items) as $index => $item) {
            $id = \is_array($item) ? ($item['id'] ?? null) : null;

            if (! \is_string($id) || $id === '' || isset($result[$id])) {
                return null;
            }

            $result[$id] = [$index, $item];
        }

        return $result;
    }

    private function pushFallbackEntries(array $old, array $new, string $path, Collection $entries): void
    {
        foreach ($this->fallback->diff($old, $new)->entries as $entry) {
            $entries->push(new DiffEntry($path . '.' . $entry->path, $entry->type, $entry->oldValue, $entry->newValue));
        }
    }
}
