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

        return new DiffResult($entries->sortBy('path')->values()->toArray());
    }

    private function diffFields(array $old, array $new, array $schema, string $prefix, Collection $entries, callable $resolver): void
    {
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $fieldType = isset($schema[$key]['type'])
                ? SchemaField::canonicalizeType((string) $schema[$key]['type'])
                : null;

            if (! array_key_exists($key, $new)) {
                $entries->push(new DiffEntry($path, DiffType::REMOVED, $old[$key], null, $fieldType));
                continue;
            }

            if (! array_key_exists($key, $old)) {
                $entries->push(new DiffEntry($path, DiffType::ADDED, null, $new[$key], $fieldType));
                continue;
            }

            $oldValue = $old[$key];
            $newValue = $new[$key];

            if ($this->valueComparer->areEqual($oldValue, $newValue)) {
                continue;
            }

            if ($fieldType === 'blocks' && \is_array($oldValue) && \is_array($newValue)) {
                $this->diffBlockItems($oldValue, $newValue, $path, $entries, $resolver);
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
            $this->pushFallbackEntries($old, $new, $path, $entries);

            return;
        }

        foreach ($oldById as $id => [$oldIndex, $oldItem]) {
            if (! isset($newById[$id])) {
                $entries->push(new DiffEntry($path . '.' . $oldIndex, DiffType::REMOVED, $oldItem, null, 'block'));
            }
        }

        foreach ($newById as $id => [$newIndex, $newItem]) {
            if (! isset($oldById[$id])) {
                $entries->push(new DiffEntry($path . '.' . $newIndex, DiffType::ADDED, null, $newItem, 'block'));
                continue;
            }

            [, $oldItem] = $oldById[$id];

            if ($this->valueComparer->areEqual($oldItem, $newItem)) {
                continue;
            }

            $slug = (string) ($newItem['block'] ?? '');

            if ($slug !== (string) ($oldItem['block'] ?? '')) {
                $entries->push(new DiffEntry($path . '.' . $newIndex, DiffType::CHANGED, $oldItem, $newItem, 'block'));
                continue;
            }

            $this->diffFields($oldItem, $newItem, $resolver($slug), $path . '.' . $newIndex, $entries, $resolver);
        }
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
