<?php

namespace App\Actions\Space;

use App\Models\Management\Space;

class ArchiveSpace
{
    public function execute(Space $space): void
    {
        $space->state = 'archived';
        $space->saveOrFail();
    }

}
