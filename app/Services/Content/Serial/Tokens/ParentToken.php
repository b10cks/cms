<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

class ParentToken implements TokenResolver
{
    use ReadsContentValues;

    public function token(): string
    {
        return 'parent';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        $parent = $context->parent;

        if ($parent === null || $argument === null || $argument === '') {
            return '';
        }

        if ($argument === 'name') {
            return (string) $parent->name;
        }

        if ($argument === 'slug') {
            return (string) $parent->slug;
        }

        return $this->stringify(data_get($context->contentValues($parent), $argument));
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return 'A field on the direct parent: `{parent:sku}`, `{parent:name}`, `{parent:slug}`.';
    }
}
