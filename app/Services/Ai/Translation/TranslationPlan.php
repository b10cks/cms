<?php

namespace App\Services\Ai\Translation;

use App\Models\Space\AiRun;
use InvalidArgumentException;

/**
 * What an approved translation run may do, frozen in the run's setup under
 * `translation`: the target locales and which kinds of work to do. The
 * translation tools enforce it; without one, every translation locale and
 * every kind of work is allowed.
 *
 * Setup shape: `['translation' => ['locales' => ['de', 'fr'], 'include' => ['missing', 'empty', 'stale']]]`,
 * plus `metadata` when the plan generates metadata from the translation: those
 * meta keys are then written by that step and never translated.
 */
final readonly class TranslationPlan
{
    /** No translation row in the locale yet: create a new translation entry. */
    public const MISSING = 'missing';

    /** The translation leaves a field empty and no fallback locale fills it. */
    public const EMPTY = 'empty';

    /** The source text changed since the translation was written. */
    public const STALE = 'stale';

    /** The translation holds the source text unchanged. Often untranslated, sometimes right (names, codes). */
    public const SAME_AS_SOURCE = 'same_as_source';

    public const KINDS = [self::MISSING, self::EMPTY, self::STALE, self::SAME_AS_SOURCE];

    /**
     * @param  list<string>|null  $locales  null allows every translation locale
     * @param  list<string>  $include
     * @param  array{keys: list<string>, overwrite_keys: list<string>, fields: list<string>, locales: list<string>}|null  $metadata
     */
    public function __construct(
        public ?array $locales = null,
        public array $include = self::KINDS,
        public ?array $metadata = null,
    ) {}

    public static function forRun(AiRun $run): self
    {
        $plan = $run->setup['translation'] ?? null;

        return \is_array($plan) ? self::fromArray($plan) : new self;
    }

    /**
     * @param  array<string, mixed>  $plan
     *
     * @throws InvalidArgumentException for an unknown kind or a malformed locale list
     */
    public static function fromArray(array $plan): self
    {
        $locales = $plan['locales'] ?? null;
        $include = $plan['include'] ?? self::KINDS;

        if ($locales !== null && (! \is_array($locales) || $locales === [] || array_filter($locales, fn ($locale) => ! \is_string($locale) || $locale === '') !== [])) {
            throw new InvalidArgumentException('translation.locales must be a non-empty list of locale codes.');
        }

        if (! \is_array($include) || $include === [] || array_diff($include, self::KINDS) !== []) {
            throw new InvalidArgumentException('translation.include must list some of: '.implode(', ', self::KINDS).'.');
        }

        return new self($locales === null ? null : array_values($locales), array_values($include), \is_array($plan['metadata'] ?? null) ? $plan['metadata'] : null);
    }

    public function includes(string $kind): bool
    {
        return \in_array($kind, $this->include, true);
    }

    /** Whether this plan generates metadata for a locale. */
    public function generatesMetadata(string $locale): bool
    {
        return $this->metadata !== null && (($this->metadata['locales'] ?? []) === [] || \in_array($locale, $this->metadata['locales'], true));
    }

    /** @return array{locales: list<string>|null, include: list<string>, metadata?: array<string, list<string>>} */
    public function toArray(): array
    {
        return ['locales' => $this->locales, 'include' => $this->include, ...($this->metadata !== null ? ['metadata' => $this->metadata] : [])];
    }
}
