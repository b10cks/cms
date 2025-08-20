<?php

namespace App\Http\Controllers;

use App\Http\Resources\Management\SpaceAiUsageResource;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiUsage;

class SpaceAiUsageController extends Controller
{
    public function __invoke(Space $space)
    {
        $usage = SpaceAiUsage::forSpace($space->id)
            ->active()
            ->orderBy('valid_to', 'desc')
            ->firstOrFail();

        return new SpaceAiUsageResource($usage);
    }
}
