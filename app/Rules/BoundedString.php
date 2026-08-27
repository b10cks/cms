<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Length-bounds a string without treating a blank one as absent.
 *
 * `string|min:1|max:4000` cannot do this. Laravel skips every non-implicit
 * rule when the value is a string that trims to nothing, so `"   "` and `""`
 * slip past `string`, `min` and `max` alike. Adding `present` does not help:
 * `present` is implicit and runs, the others still do not. A closure rule is
 * not implicit either. Marking this rule implicit is what makes it run.
 *
 * Because it is implicit it also fires on a missing or null value, so it
 * carries `required` semantics. Pair it with `nullable`/`sometimes` if that is
 * not what you want.
 */
class BoundedString implements ValidationRule
{
    /** Runs even when the value is blank or absent, which is the whole point. */
    public bool $implicit = true;

    public function __construct(
        private readonly int $min,
        private readonly int $max,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('validation.string')->translate();

            return;
        }

        $length = mb_strlen($value);

        if ($length < $this->min) {
            $fail('validation.min.string')->translate(['min' => $this->min]);

            return;
        }

        if ($length > $this->max) {
            $fail('validation.max.string')->translate(['max' => $this->max]);
        }
    }
}
