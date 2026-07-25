<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class DateToken implements TokenResolver
{
    /**
     * Date-only format characters. Anything else — including `\` escapes and
     * literal text — is rejected at schema-validation time, so a format string
     * can never smuggle arbitrary output into an identifier.
     */
    public const string ALLOWED_FORMAT = '/^[YymndjWNL]{1,8}$/';

    public function token(): string
    {
        return 'date';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        $format = $argument !== null && $argument !== '' && preg_match(self::ALLOWED_FORMAT, $argument)
            ? $argument
            : 'Y';

        return $context->now()->format($format);
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return 'The creation date: `{date:Y}` → 2026, `{date:Ym}` → 202607.';
    }
}
