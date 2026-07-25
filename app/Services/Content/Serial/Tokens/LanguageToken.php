<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class LanguageToken implements TokenResolver
{
    public function token(): string
    {
        return 'lang';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        return $argument === 'upper'
            ? strtoupper($context->languageIso)
            : $context->languageIso;
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return 'The language code, `{lang:upper}` for uppercase. Note that translations share their serial.';
    }
}
