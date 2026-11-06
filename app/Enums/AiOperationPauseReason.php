<?php

namespace App\Enums;

/** Why an operation paused. Each needs an explicit editor action to continue. */
enum AiOperationPauseReason: string
{
    /** The shared budget is spent; continuing needs a budget extension. */
    case Budget = 'budget';
    case User = 'user';
    case AccessRevoked = 'access_revoked';
    case BatchFailed = 'batch_failed';
    /** Someone cancelled the batch run itself instead of the operation. */
    case BatchCancelled = 'batch_cancelled';
}
