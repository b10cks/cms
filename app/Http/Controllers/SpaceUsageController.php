<?php

namespace App\Http\Controllers;

use App\Http\Resources\Management\SpaceUsageResource;
use App\Models\Management\Space;
use App\Services\Space\SpaceUsageService;

class SpaceUsageController extends Controller
{
    public function __invoke(Space $space, SpaceUsageService $usage): SpaceUsageResource
    {
        $this->authorize('view', $space);

        return new SpaceUsageResource($usage->forSpace($space));
    }
}
