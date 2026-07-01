<?php

namespace App\Exceptions;

use App\Models\Space\Asset;

class DuplicateAssetException extends \RuntimeException
{
    public function __construct(public readonly Asset $existingAsset)
    {
        parent::__construct('An asset with identical content already exists in this space.');
    }
}
