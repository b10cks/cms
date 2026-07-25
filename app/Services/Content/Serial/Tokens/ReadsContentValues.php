<?php

namespace App\Services\Content\Serial\Tokens;

/**
 * Serial values are flat strings. Anything a field can hold that is not scalar
 * (a richtext document, an asset object, a block list) has no sensible textual
 * identity, so it renders empty rather than as `Array` or a JSON blob.
 */
trait ReadsContentValues
{
    protected function stringify(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
