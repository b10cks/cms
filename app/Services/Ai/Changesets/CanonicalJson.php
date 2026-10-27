<?php

namespace App\Services\Ai\Changesets;

/**
 * Key-order independent comparison. MySQL JSON columns reorder object keys,
 * so a value read back can differ in order from the one a run saw.
 */
final class CanonicalJson
{
    public static function equals(mixed $a, mixed $b): bool
    {
        return self::encode($a) === self::encode($b);
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
    }

    private static function sort(mixed $value): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }

        $value = array_map(self::sort(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
