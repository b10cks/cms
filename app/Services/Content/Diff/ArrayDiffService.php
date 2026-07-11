<?php

namespace App\Services\Content\Diff;

class ArrayDiffService implements DiffInterface
{
    private PathNormalizer $pathNormalizer;
    private ValueComparer $valueComparer;

    public function __construct(
        ?PathNormalizer $pathNormalizer = null,
        ?ValueComparer $valueComparer = null
    ) {
        $this->pathNormalizer = $pathNormalizer ?? new PathNormalizer();
        $this->valueComparer = $valueComparer ?? new ValueComparer();
    }

    public function diff(array $old, array $new): DiffResult
    {
        $oldFlattened = $this->flattenArray($old);
        $newFlattened = $this->flattenArray($new);

        $entries = collect();

        $this->processRemovedEntries($oldFlattened, $newFlattened, $entries);
        $this->processAddedEntries($oldFlattened, $newFlattened, $entries);
        $this->processChangedEntries($oldFlattened, $newFlattened, $entries);

        return new DiffResult($entries->sortBy('path')->values()->toArray());
    }

    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $currentPath = $this->pathNormalizer->buildPath($prefix, $key);

            if (is_array($value) && !self::isRichTextDoc($value)) {
                $result = array_merge($result, $this->flattenArray($value, $currentPath));
            } else {
                $result[$currentPath] = $value;
            }
        }

        return $result;
    }

    /**
     * Rich text fields hold a ProseMirror document. Keep it as a single
     * leaf so a change surfaces as one entry carrying both full documents,
     * instead of being flattened into per-node paths.
     */
    public static function isRichTextDoc(mixed $value): bool
    {
        return \is_array($value)
            && ($value['type'] ?? null) === 'doc'
            && array_key_exists('content', $value);
    }

    private function processRemovedEntries(array $old, array $new, $entries): void
    {
        foreach ($old as $path => $value) {
            if (!array_key_exists($path, $new)) {
                $entries->push(new DiffEntry($path, DiffType::REMOVED, $value));
            }
        }
    }

    private function processAddedEntries(array $old, array $new, $entries): void
    {
        foreach ($new as $path => $value) {
            if (!array_key_exists($path, $old)) {
                $entries->push(new DiffEntry($path, DiffType::ADDED, null, $value));
            }
        }
    }

    private function processChangedEntries(array $old, array $new, $entries): void
    {
        foreach ($old as $path => $oldValue) {
            if (array_key_exists($path, $new)) {
                $newValue = $new[$path];

                if (!$this->valueComparer->areEqual($oldValue, $newValue)) {
                    $entries->push(new DiffEntry($path, DiffType::CHANGED, $oldValue, $newValue));
                }
            }
        }
    }
}
