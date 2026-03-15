<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AutomationActionFilter;
use App\Http\Requests\AutomationAction\AutomationActionCreateRequest;
use App\Http\Requests\AutomationAction\AutomationActionUpdateRequest;
use App\Http\Resources\Management\AutomationActionResource;
use App\Models\Management\AutomationAction;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AutomationActionController extends Controller
{
    public function index(Space $space, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AutomationAction::class, $space]);

        $actions = AutomationAction::query()
            ->filter(AutomationActionFilter::fromRequest($request))
            ->where('space_id', $space->id)
            ->withCount('automations')
            ->paginate();

        return AutomationActionResource::collection($actions);
    }

    public function store(
        Space $space,
        AutomationActionCreateRequest $request,
    ): JsonResponse {
        $this->authorize('create', [AutomationAction::class, $space]);

        $action = new AutomationAction($request->safe()->except(['secrets']));
        $action->space_id = $space->id;
        $action->secrets = $request->validated('secrets');
        $action->save();

        return (new AutomationActionResource($action->loadCount('automations')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        Space $space,
        AutomationAction $automationAction,
    ): AutomationActionResource {
        $this->authorize('view', [$automationAction, $space]);

        return new AutomationActionResource($automationAction->loadCount('automations'));
    }

    public function update(
        Space $space,
        AutomationActionUpdateRequest $request,
        AutomationAction $automationAction,
    ): AutomationActionResource {
        $this->authorize('update', [$automationAction, $space]);

        $payload = $request->safe()->except(['secrets', 'clear_secret_keys']);
        $automationAction->fill($payload);

        if ($request->has('secrets') || $request->filled('clear_secret_keys')) {
            $secrets = $automationAction->secrets ?? [];

            foreach ((array) $request->input('clear_secret_keys', []) as $key) {
                unset($secrets[$key]);
            }

            foreach ((array) $request->input('secrets', []) as $key => $value) {
                $secrets[$key] = $value;
            }

            $automationAction->secrets = $secrets === [] ? null : $secrets;
        }

        $automationAction->save();

        return new AutomationActionResource($automationAction->loadCount('automations'));
    }

    public function destroy(
        Space $space,
        AutomationAction $automationAction,
    ): JsonResponse {
        $this->authorize('delete', [$automationAction, $space]);

        if ($automationAction->automations()->exists()) {
            throw ValidationException::withMessages([
                'action' => ['This action is still linked to one or more automations and cannot be deleted yet.'],
            ]);
        }

        $automationAction->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
