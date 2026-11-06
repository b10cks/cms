<?php

namespace App\Services\Ai\Metadata;

/**
 * Applies generated values to a `meta` field, which is one object in the
 * content document. Only the keys a write names change: canonical, robots,
 * ogImage and anything else already stored stay. Under a policy (`$writable`
 * set) a key outside it is invalid, and a key with a stored value is kept
 * unless `$overwrite` allows replacing it. Empty output never clears a value
 * there. Without a policy (a run outside an operation) any key may be
 * set or cleared, the text limits still apply.
 */
final class MetadataOutputs
{
    /** What AI generates. The other keys of a meta field belong to editors. */
    public const KEYS = ['title', 'description', 'ogTitle', 'ogDescription'];

    /** The limits of the meta-tags skill, enforced here because prompts alone do not. */
    private const MAX_LENGTHS = ['title' => 60, 'description' => 155];

    /**
     * @param  array<string, mixed>  $stored  the field as stored; decides what counts as existing
     * @param  array<string, mixed>  $base  what the change builds on: the stored field or this task's own value for it
     * @param  list<string>|null  $writable  null means no policy
     * @param  list<string>  $overwrite  keys whose stored value may be replaced
     * @return array{value: array<string, mixed>, written: list<string>, kept: list<string>, invalid: array<string, string>}
     */
    public static function apply(array $stored, array $base, mixed $generated, ?array $writable, array $overwrite = []): array
    {
        if (! \is_array($generated) || ($generated !== [] && array_is_list($generated))) {
            return ['value' => $base, 'written' => [], 'kept' => [], 'invalid' => ['*' => 'must be an object of metadata keys.']];
        }

        $value = $base;
        $written = [];
        $kept = [];
        $invalid = [];

        foreach ($generated as $key => $new) {
            $key = (string) $key;
            $problem = self::problem($key, $new, $writable);
            if ($problem !== null) {
                $invalid[$key] = $problem;

                continue;
            }

            $text = \is_string($new) ? trim($new) : $new;
            if ($writable !== null) {
                $exists = trim((string) ($stored[$key] ?? '')) !== '';
                if ($text === '' || ($exists && ($text === trim((string) $stored[$key]) || ! \in_array($key, $overwrite, true)))) {
                    if ($exists) {
                        $kept[] = $key;
                    } elseif ($text === '') {
                        $invalid[$key] = 'is empty. Report it as uninferable or intentionally_empty instead.';
                    }

                    continue;
                }
            }

            // Also when this task wrote it already: a repeated call must still account for the key.
            $value[$key] = $text;
            $written[] = $key;
        }

        return ['value' => $value, 'written' => $written, 'kept' => $kept, 'invalid' => $invalid];
    }

    /** @param  list<string>|null  $writable */
    private static function problem(string $key, mixed $value, ?array $writable): ?string
    {
        if ($writable !== null && ! \in_array($key, $writable, true)) {
            return 'is not a key the approved plan writes: '.implode(', ', $writable).'.';
        }
        if ($writable !== null && ! \is_string($value)) {
            return 'must be text.';
        }

        $limit = self::MAX_LENGTHS[$key] ?? null;

        return $limit !== null && \is_string($value) && mb_strlen(trim($value)) > $limit ? "must be at most {$limit} characters." : null;
    }
}
