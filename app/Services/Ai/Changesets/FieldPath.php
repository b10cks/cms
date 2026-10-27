<?php

namespace App\Services\Ai\Changesets;

use App\Services\Ai\Run\ToolError;

/**
 * Dot paths into a document that address list items by their `id`, never by
 * array index: `body.01HX….text`. Index paths shift when blocks move; ID
 * paths still point at the same field when a proposal is accepted later.
 */
final class FieldPath
{
    public static function get(array $document, string $path): mixed
    {
        $node = $document;

        foreach (explode('.', $path) as $segment) {
            if (! \is_array($node)) {
                return null;
            }

            $key = self::keyOf($node, $segment);
            if ($key === null) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @throws ToolError when a list item ID does not exist
     */
    public static function set(array $document, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $segment = array_shift($segments);

        if ($segments === []) {
            $key = array_is_list($document) && $document !== [] ? self::keyOf($document, $segment) : $segment;
            if ($key === null) {
                throw new ToolError("No item with id \"{$segment}\" at this path.");
            }
            $document[$key] = $value;

            return $document;
        }

        $key = self::keyOf($document, $segment);
        if ($key === null && array_is_list($document) && $document !== []) {
            throw new ToolError("No item with id \"{$segment}\" at this path.");
        }

        $key ??= $segment;
        $child = \is_array($document[$key] ?? null) ? $document[$key] : [];
        $document[$key] = self::set($child, implode('.', $segments), $value);

        return $document;
    }

    /** The same path with list items addressed by index, as validation errors are keyed. */
    public static function toIndexPath(array $document, string $path): ?string
    {
        $node = $document;
        $keys = [];

        foreach (explode('.', $path) as $segment) {
            $key = \is_array($node) ? self::keyOf($node, $segment) : null;
            $key ??= \is_array($node) && ! array_is_list($node) ? $segment : null;
            if ($key === null) {
                return null;
            }

            $keys[] = $key;
            $node = \is_array($node) ? ($node[$key] ?? null) : null;
        }

        return implode('.', $keys);
    }

    /** Key of a segment: the index of the item with that `id` in a list, else the map key. */
    private static function keyOf(array $node, string $segment): int|string|null
    {
        if ($node !== [] && array_is_list($node)) {
            foreach ($node as $index => $item) {
                if (\is_array($item) && ($item['id'] ?? null) === $segment) {
                    return $index;
                }
            }

            return null;
        }

        return \array_key_exists($segment, $node) ? $segment : null;
    }
}
