<?php

namespace App\Models\Traits;

use App\Models\Management\Space;

trait SpaceFromQuery
{
    public function getSpaceFromQuery()
    {
        $space = Space::findOrFail(request()->query('spaceId'));
        abort_unless(\Gate::allows('view', $space), 404);

        return $space;
    }
}
