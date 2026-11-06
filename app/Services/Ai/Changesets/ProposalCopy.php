<?php

namespace App\Services\Ai\Changesets;

/**
 * The words of a stored value: strings outside identifiers and settings,
 * ProseMirror text nodes included.
 */
final class ProposalCopy
{
    /** Keys that hold identifiers or settings, never copy. */
    private const NON_COPY_KEYS = ['id', 'block', 'block_id', 'type', 'marks', 'attrs', 'parent_id', 'i18n_parent_id', 'language_iso', 'slug', 'canonical', 'robots', 'ogImage', 'external_id'];

    private const IDENTIFIER = '/^([0-9a-hjkmnp-tv-z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|https?:\/\/\S+)$/i';

    public static function text(mixed $value): string
    {
        return implode("\n", self::strings($value));
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (\is_string($value)) {
            return trim($value) === '' || preg_match(self::IDENTIFIER, trim($value)) ? [] : [$value];
        }
        if (! \is_array($value)) {
            return [];
        }

        $found = [];
        foreach ($value as $key => $nested) {
            if (! \in_array($key, self::NON_COPY_KEYS, true)) {
                array_push($found, ...self::strings($nested));
            }
        }

        return $found;
    }
}
