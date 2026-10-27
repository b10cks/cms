<?php

namespace App\Enums;

enum AiChangesetItemStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
}
