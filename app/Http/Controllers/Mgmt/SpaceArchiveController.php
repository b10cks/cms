<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Space\ArchiveSpace;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;

class SpaceArchiveController extends Controller
{
    public function __invoke(Space $space, ArchiveSpace $action)
    {
        $this->authorize('archive', $space);

        $action->execute($space);

        return response()->noContent(204);
    }
}
