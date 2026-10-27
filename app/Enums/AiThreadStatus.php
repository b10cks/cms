<?php

namespace App\Enums;

/** Derived from a thread's runs, changesets and suggestions; never stored. */
enum AiThreadStatus: string
{
    case NeedsInput = 'needs_input';
    case NeedsReview = 'needs_review';
    case Running = 'running';
    case Failed = 'failed';
    case Done = 'done';

}
