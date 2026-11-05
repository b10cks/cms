<?php

namespace App\Services\Ai\Translation;

use App\Services\Ai\Contracts\ReportsOutcomes;

/**
 * The translation work for one source entry in one locale: the units to
 * write with the reason for each, and the units left alone with why.
 */
final readonly class TranslationTask
{
    public const STATUS_MISSING = 'missing';

    public const STATUS_OUTDATED = 'outdated';

    public const STATUS_CURRENT = 'current';

    /** There is work, but not of a kind the run was approved for. */
    public const STATUS_SKIPPED = 'skipped';

    /** The translation is up to date and the source has not changed since. */
    public const HELD_CURRENT = 'current';

    /** Empty here, filled by a fallback locale. */
    public const HELD_INHERITED = 'inherited';

    /** The block holding the field is not in this standalone translation; adding it is an editor's call. */
    public const HELD_NOT_IN_TRANSLATION = 'not_in_translation';

    /** Not a kind of work the run was approved for. */
    public const HELD_NOT_IN_PLAN = 'not_in_plan';

    /** A meta key the approved plan generates from the translation instead of translating it. */
    public const HELD_GENERATED = 'generated';

    /**
     * @param  array<string, array{id: string, field: string, label: string, type: string, reason: string, source: string, current: string, previous_source?: string}>  $units  work, by unit ID
     * @param  array<string, string>  $held  unit ID => HELD_* reason
     */
    public function __construct(
        public TranslationDestination $destination,
        public array $units,
        public array $held,
        public bool $createApproved,
    ) {}

    public function status(): string
    {
        $missing = $this->destination->translation === null;

        return match (true) {
            $missing => $this->createApproved ? self::STATUS_MISSING : self::STATUS_SKIPPED,
            $this->units !== [] => self::STATUS_OUTDATED,
            \in_array(self::HELD_NOT_IN_PLAN, $this->held, true) => self::STATUS_SKIPPED,
            default => self::STATUS_CURRENT,
        };
    }

    /**
     * The outcome of this locale when code can settle it without the model:
     * up to date, or not approved work. Null while there is work to write.
     *
     * @return array{outcome: string, reason: string}|null
     */
    public function settled(): ?array
    {
        return match ($this->status()) {
            self::STATUS_CURRENT => ['outcome' => ReportsOutcomes::UNCHANGED, 'reason' => 'up_to_date'],
            self::STATUS_SKIPPED => ['outcome' => ReportsOutcomes::SKIPPED, 'reason' => self::HELD_NOT_IN_PLAN],
            default => null,
        };
    }

    /** @return array<string, int> reason => units, for work and held units */
    public function counts(): array
    {
        return array_count_values([...array_column($this->units, 'reason'), ...array_values($this->held)]);
    }
}
