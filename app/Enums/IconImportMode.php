<?php

namespace App\Enums;

enum IconImportMode: string
{
    /** Add new icons and overwrite existing ones with the same key. */
    case Addition = 'addition';

    /** Prune the space's existing icons first, then import the whole set. */
    case Replacement = 'replacement';
}
