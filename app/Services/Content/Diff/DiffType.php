<?php

namespace App\Services\Content\Diff;

enum DiffType: string
{
    case ADDED = 'added';
    case REMOVED = 'removed';
    case CHANGED = 'changed';
}
