<?php

namespace App\Services\Ai\Knowledge;

/**
 * What one language of a glossary row says. With Avoid and no Use it is a ban.
 */
final readonly class GlossaryWording
{
    /**
     * @param  string  $locale  normalized, see {@see GlossaryMatcher::normalizeLocale()}
     * @param  list<string>  $avoid  wording never to write, a `*` at the end covers longer forms
     * @param  list<string>  $also  how a source may spell this same term
     * @param  string|null  $label  what this language goes by when that is neither, only for {@see FrozenGlossaryRow}
     */
    public function __construct(
        public string $locale,
        public ?string $use = null,
        public array $avoid = [],
        public array $also = [],
        public ?string $label = null,
    ) {}

    /** What this language goes by: its Use, or the first Avoid of a ban. */
    public function name(): string
    {
        return $this->label ?? $this->use ?? $this->avoid[0] ?? '';
    }

    public function isBan(): bool
    {
        return $this->use === null;
    }
}
