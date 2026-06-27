<?php

namespace App\Exceptions;

use App\Models\Space\ContentVersion;

class ContentVersionConflictException extends \RuntimeException
{
    public function __construct(public readonly ContentVersion $currentVersion)
    {
        parent::__construct('Content version conflict');
    }
}
