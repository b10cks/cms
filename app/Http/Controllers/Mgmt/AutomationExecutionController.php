<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AutomationExecutionFilter;
use App\Http\Resources\Management\AutomationExecutionResource;
use App\Models\Management\Automation;
use App\Models\Management\AutomationExecution;
use App\Models\Management\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AutomationExecutionController extends Controller
{
    public function index(Space $space, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Automation::class, $space]);

        $executions = AutomationExecution::query()
            ->with(['automation.action'])
            ->whereHas('automation', fn ($query) => $query->where('space_id', $space->id))
            ->filter(AutomationExecutionFilter::fromRequest($request))
            ->paginate();

        return AutomationExecutionResource::collection($executions);
    }
}
