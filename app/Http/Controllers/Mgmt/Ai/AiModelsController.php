<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\ModelRegistry;
use Illuminate\Http\JsonResponse;

class AiModelsController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected ModelRegistry $registry
    ) {}

    public function __invoke(): JsonResponse
    {
        $space = $this->getSpaceFromQuery();

        $groupedModels = $this->registry->getGroupedModels($space);

        return response()->json([
            'data' => $groupedModels,
        ]);
    }
}
