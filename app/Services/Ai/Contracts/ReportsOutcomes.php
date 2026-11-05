<?php

namespace App\Services\Ai\Contracts;

/**
 * A tool that decided, for items of the run's scope, that they were written,
 * proposed, need no change, or were skipped (with a reason), so an operation can
 * account for every scoped item without trusting the model's summary.
 */
interface ReportsOutcomes
{
    /** Content: the change is in the draft. */
    public const WRITTEN = 'written';

    /** Assets, data entries and blocks: the change waits for a person. */
    public const PROPOSED = 'proposed';

    /** Content: a person changed what the agent read, so nothing was written. Not fulfilled, and a later write replaces it. */
    public const CONFLICT = 'conflict';

    /** Outcomes that delivered the output: it stays as it is, and a retry owes the other parts. */
    public const DELIVERED = [self::WRITTEN, self::PROPOSED];

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** Part outcomes: the stored value already fulfils the output. Code decides this, or the part was open to overwrite. */
    public const SATISFIED = 'satisfied';

    /** Part outcomes: the output is right to stay empty, such as the alt text of a decorative image. */
    public const INTENTIONALLY_EMPTY = 'intentionally_empty';

    /** Part outcomes: the inputs do not support a value. */
    public const UNINFERABLE = 'uninferable';

    /** Part outcomes: the run declined this output, with its reason. */
    public const REFUSED = 'refused';

    /** Part outcomes: a missing fact blocks this output; the part keeps the question to ask. */
    public const BLOCKED = 'blocked';

    /** Part outcomes: the last attempt was refused. The part stays owed until a retry fixes it or attempts run out. */
    public const INVALID = 'invalid';

    /** Part outcomes: attempts ran out with nothing recorded. */
    public const UNFINISHED = 'unfinished';

    /** Progress inside a part: its `units` were written, others remain. Settles nothing. */
    public const PARTIAL = 'partial';

    /**
     * Outcomes of the last call, then forgotten. `part` names one part of
     * the entry, such as a locale of a translation source; an operation
     * entry is done once each part it expects has an outcome.
     *
     * @return list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part?: string, note?: string, units?: array{written: list<string>, remaining: list<string>}}>
     */
    public function takeOutcomes(): array;
}
