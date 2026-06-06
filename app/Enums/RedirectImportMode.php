<?php

namespace App\Enums;

enum RedirectImportMode: string
{
    case Addition = 'addition';
    case Replacement = 'replacement';
}
