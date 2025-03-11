<?php

namespace App\Services\Content\Schema\Types;

class BlockTypeHandler extends AbstractTypeHandler
{
    public function getType(): string
    {
        return 'block';
    }
}
