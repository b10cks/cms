<?php

namespace App\Services\Content\Serial;

use RuntimeException;

/**
 * The rendered value already exists inside the field's uniqueness scope.
 *
 * The allocator deliberately does not try to work around this by skipping
 * numbers: with `unique: space` it is the *format's* job to stay distinct
 * across blocks (e.g. `H-{counter}` vs `C-{counter}`). Silently renumbering
 * would hide a misconfigured format behind ever-growing counters.
 */
class SerialCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly string $fieldLabel,
        public readonly string $value,
    ) {
        parent::__construct(sprintf(
            'The generated value "%s" for %s already exists.',
            $value,
            $fieldLabel,
        ));
    }
}
