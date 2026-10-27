<?php

namespace App\Enums;

enum AiChangesetStatus: string
{
    case Open = 'open';
    case PartiallyApplied = 'partially_applied';
    case Applied = 'applied';
    case Discarded = 'discarded';
}
