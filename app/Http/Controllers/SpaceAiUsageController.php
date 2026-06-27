<?php

namespace App\Http\Controllers;

use App\Http\Resources\Management\SpaceAiUsageResource;
use App\Models\Management\Space;
use App\Services\Ai\SpaceAiUsageService;
use Illuminate\Http\Request;

class SpaceAiUsageController extends Controller
{
    public function __invoke(Request $request, Space $space, SpaceAiUsageService $usage): SpaceAiUsageResource
    {
        return new SpaceAiUsageResource(
            $usage->forSpace($space, $request->boolean('refresh'))
        );
    }
}
