<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class FieldToken implements TokenResolver
{
    use ReadsContentValues;

    public function token(): string
    {
        return 'field';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        if ($argument === null || $argument === '') {
            return '';
        }

        return $this->stringify(data_get($context->values, $argument));
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return "A field on the entry itself: `{field:name}`. Must be filled when the entry is created.";
    }
}
