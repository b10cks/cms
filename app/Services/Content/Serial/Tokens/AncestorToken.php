<?php

namespace App\Services\Content\Serial\Tokens;

use App\Services\Content\Serial\SerialContext;
use App\Services\Content\Serial\TokenResolver;

/**
 * The nearest ancestor carrying a non-empty value for the requested key.
 *
 * This is what makes prefixes survive intermediate structure: a house filed
 * under `Holzhaus / Archived / 2026` still picks up `Holzhaus`'s number,
 * whereas `{parent:…}` would resolve against the folder and render empty.
 */
class AncestorToken implements TokenResolver
{
    use ReadsContentValues;

    public function token(): string
    {
        return 'ancestor';
    }

    public function resolve(?string $argument, SerialContext $context): string
    {
        if ($argument === null || $argument === '') {
            return '';
        }

        foreach ($context->ancestors() as $ancestor) {
            $value = match ($argument) {
                'name' => $this->stringify($ancestor->name),
                'slug' => $this->stringify($ancestor->slug),
                default => $this->stringify(data_get($context->contentValues($ancestor), $argument)),
            };

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function requiresNumber(): bool
    {
        return false;
    }

    public function hint(): string
    {
        return 'The nearest ancestor with a value for the key: `{ancestor:house_no}`.';
    }
}
