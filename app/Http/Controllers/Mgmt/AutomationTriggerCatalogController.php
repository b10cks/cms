<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Services\Automation\TriggerCatalog;
use Illuminate\Http\JsonResponse;

class AutomationTriggerCatalogController extends Controller
{
    public function __invoke(
        Space $space,
        TriggerCatalog $triggerCatalog,
    ): JsonResponse {
        $this->authorize('viewAny', [Automation::class, $space]);

        return response()->json([
            'data' => [
                'tables' => $triggerCatalog->forSpace($space),
                'content_lifecycle' => array_values($triggerCatalog->contentLifecycleTriggers()),
            ],
        ]);
    }
}
