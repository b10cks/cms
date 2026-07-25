<?php

namespace App\Services\Content\Serial;

/**
 * Rewrites the block and parent ids embedded in a scope key.
 *
 * Used when the serial ledger crosses space boundaries: the ids inside a scope
 * key are meaningless in the target space, so they have to be translated
 * through the migration's id maps. Pure string work, kept out of the migration
 * job so it can be reasoned about — and tested — on its own.
 */
class ScopeKeyRemapper
{
    /**
     * @param  array<string, string>  $blockIdMap  source block id => target block id
     * @param  array<string, string>  $contentIdMap  source content id => target content id
     * @return string|null null when a referenced id has no mapping, which means
     *                     the key cannot be translated without silently merging
     *                     two distinct scopes into one
     */
    public function remap(string $scopeKey, array $blockIdMap, array $contentIdMap): ?string
    {
        $segments = [];

        foreach (explode('|', $scopeKey) as $segment) {
            [$prefix, $value] = array_pad(explode(':', $segment, 2), 2, null);

            // Dimensions without an id (`sp`, `y:2026`, `par:-`) travel as-is.
            if ($value === null || $value === '-') {
                $segments[] = $segment;

                continue;
            }

            $mapped = match ($prefix) {
                'blk' => $blockIdMap[$value] ?? null,
                'par' => $contentIdMap[$value] ?? null,
                default => $value,
            };

            if ($mapped === null) {
                return null;
            }

            $segments[] = $prefix.':'.$mapped;
        }

        return implode('|', $segments);
    }
}
