<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AutomationFilter;
use App\Http\Requests\Automation\AutomationCreateRequest;
use App\Http\Requests\Automation\AutomationUpdateRequest;
use App\Http\Resources\Management\AutomationResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AutomationController extends Controller
{
    public function index(Space $space, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Automation::class, $space]);

        $automations = Automation::query()
            ->filter(AutomationFilter::fromRequest($request))
            ->where('space_id', $space->id)
            ->with('action')
            ->paginate($this->perPage($request));

        return AutomationResource::collection($automations);
    }

    public function store(
        Space $space,
        AutomationCreateRequest $request
    ): JsonResponse {
        $this->authorize('create', [Automation::class, $space]);

        $automation = new Automation($request->validated());
        $automation->space_id = $space->id;
        $automation->save();

        return (new AutomationResource($automation->load('action')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        Space $space,
        Automation $automation
    ): AutomationResource {
        $this->authorize('view', [$automation, $space]);

        return new AutomationResource($automation->load('action'));
    }

    public function update(
        Space $space,
        AutomationUpdateRequest $request,
        Automation $automation
    ): AutomationResource {
        $this->authorize('update', [$automation, $space]);

        $automation->update($request->validated());

        return new AutomationResource($automation->load('action'));
    }

    public function destroy(
        Space $space,
        Automation $automation
    ): JsonResponse {
        $this->authorize('delete', [$automation, $space]);

        $automation->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
