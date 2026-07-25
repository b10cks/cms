<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class BlockToken implements TokenResolver
{
    public function token(): string
    {
        return 'block';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        return $argument === 'name'
            ? (string) $context->block->name
            : (string) $context->block->slug;
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return 'The block slug, or `{block:name}` for its display name.';
    }
}
