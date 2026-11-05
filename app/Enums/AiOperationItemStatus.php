<?php

namespace App\Enums;

/**
 * Outcome of one entry of an operation. `pending` and `running` are work
 * still owed; the others are final.
 */
enum AiOperationItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Proposed = 'proposed';
    /** The agent's change is in the entry's draft. */
    case Written = 'written';
    /** Nothing was written: a person changed what the agent read, and a person decides. */
    case Conflict = 'conflict';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /** @return list<string> */
    public static function owed(): array
    {
        return [self::Pending->value, self::Running->value];
    }
}
