<?php

namespace App\Services\Content\Serial;

/**
 * Turns a field's `scope` / `unique` configuration into the keys the ledger's
 * unique indexes are built on.
 *
 * Adding a way to partition counters is one more `match` arm here — the table,
 * the allocator and the renderer stay untouched.
 */
class ScopeKeyBuilder
{
    public const array DIMENSIONS = ['space', 'block', 'parent', 'language', 'year', 'month'];

    public const array UNIQUE_MODES = ['scope', 'block', 'space', 'none'];

    public const array DEFAULT_SCOPE = ['block', 'parent'];

    /**
     * Dimensions are emitted in a fixed order so that `["parent","block"]` and
     * `["block","parent"]` describe the same counter.
     *
     * @param  array<int, string>  $dimensions
     */
    public function scopeKey(array $dimensions, SerialContext $context): string
    {
        $selected = array_values(array_filter(
            self::DIMENSIONS,
            static fn (string $dimension): bool => in_array($dimension, $dimensions, true),
        ));

        if ($selected === []) {
            $selected = self::DEFAULT_SCOPE;
        }

        $segments = [];

        foreach ($selected as $dimension) {
            $segments[] = match ($dimension) {
                'space' => 'sp',
                'block' => 'blk:'.$context->block->id,
                'parent' => 'par:'.($context->parent?->id ?? '-'),
                'language' => 'lng:'.$context->languageIso,
                'year' => 'y:'.$context->now()->format('Y'),
                'month' => 'm:'.$context->now()->format('Y-m'),
            };
        }

        return implode('|', $segments);
    }

    /**
     * The partition the rendered value must be unique within, or null when the
     * field enforces no uniqueness.
     *
     * @param  array<int, string>  $dimensions
     */
    public function uniqueKey(string $mode, array $dimensions, SerialContext $context): ?string
    {
        return match ($mode) {
            'none' => null,
            'block' => 'blk:'.$context->block->id,
            'space' => 'sp',
            default => $this->scopeKey($dimensions, $context),
        };
    }
}
