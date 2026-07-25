<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class CounterToken implements TokenResolver
{
    public const int MAX_PADDING = 12;

    public function token(): string
    {
        return 'counter';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        $number = (string) ($context->number ?? 0);
        $padding = (int) $argument;

        if ($padding < 1 || $padding > self::MAX_PADDING) {
            return $number;
        }

        return str_pad($number, $padding, '0', STR_PAD_LEFT);
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function hint(): string
    {
        return 'The allocated number. `{counter:3}` pads it to three digits.';
    }
}
