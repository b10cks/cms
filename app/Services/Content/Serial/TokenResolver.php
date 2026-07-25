<?php

namespace App\Services\Content\Serial;

/**
 * One token in a serial format or slug pattern.
 *
 * Adding a token is implementing this interface and registering the class in
 * `ContentServiceProvider` — no other file needs to change.
 */
interface TokenResolver
{
    /**
     * The token name, without braces: `counter` for `{counter:3}`.
     */
    public function token(): string;

    /**
     * The argument after the colon, or null when the token was used bare.
     */
    public function resolve(?string $argument, SerialContext $context): string;

    /**
     * Whether this token needs an allocated number. Tokens that do can only be
     * used in serial formats, never in slug patterns.
     */
    public function requiresNumber(): bool;

    /**
     * Short description surfaced in the block designer's token picker.
     */
    public function hint(): string;
}
