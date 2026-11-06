<?php

namespace App\Enums;

use App\Services\Ai\Operations\OperationQuestions;

/**
 * Lifecycle of an AI content operation. `draft` holds a frozen scope and an
 * editable plan; approval freezes the plan and starts `running`. A budget
 * hit, lost access, a failed batch or the editor's request pause it; only an
 * explicit resume or budget extension continues. `completed` means every
 * ledger item has an outcome, some maybe `blocked` on a question: an editor's
 * answer returns it to `running` for those parts ({@see OperationQuestions}).
 * Only `cancelled` is final.
 */
enum AiOperationState: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
