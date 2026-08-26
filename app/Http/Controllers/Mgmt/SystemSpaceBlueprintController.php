<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Blueprint\CreateSpaceBlueprint;
use App\Actions\Blueprint\UpdateSpaceBlueprint;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesBlueprintSourceSpace;
use App\Http\Requests\SpaceBlueprint\StoreSpaceBlueprintRequest;
use App\Http\Requests\SpaceBlueprint\UpdateSpaceBlueprintRequest;
use App\Http\Resources\Management\SpaceBlueprintResource;
use App\Models\Management\SpaceBlueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * System blueprints belong to no team: every user can start a space from them,
 * so only root may create or change one. The listing lives in
 * {@see AvailableSpaceBlueprintController}, which mixes them with team ones.
 */
class SystemSpaceBlueprintController extends Controller
{
    use ResolvesBlueprintSourceSpace;

    /**
     * Create a blueprint that belongs to no team.
     *
     * Root only: the result is offered to every user on the instance.
     */
    public function store(StoreSpaceBlueprintRequest $request, CreateSpaceBlueprint $action): SpaceBlueprintResource
    {
        $this->authorize('create', [SpaceBlueprint::class, null]);

        $blueprint = $action->execute(
            $request->validated(),
            null,
            $request->user(),
            $this->resolveSourceSpace($request),
        );

        return new SpaceBlueprintResource($blueprint->load('creator'));
    }

    /**
     * Get a single blueprint that belongs to no team.
     */
    public function show(SpaceBlueprint $blueprint): SpaceBlueprintResource
    {
        $this->ensureSystemBlueprint($blueprint);
        $this->authorize('view', $blueprint);

        return new SpaceBlueprintResource($blueprint->load('creator'));
    }

    /**
     * Update a blueprint that belongs to no team. Root only.
     */
    public function update(
        UpdateSpaceBlueprintRequest $request,
        SpaceBlueprint $blueprint,
        UpdateSpaceBlueprint $action
    ): SpaceBlueprintResource {
        $this->ensureSystemBlueprint($blueprint);
        $this->authorize('update', $blueprint);

        $blueprint = $action->execute($blueprint, $request->validated());

        return new SpaceBlueprintResource($blueprint->load('creator'));
    }

    /**
     * Delete a blueprint that belongs to no team. Root only.
     */
    public function destroy(SpaceBlueprint $blueprint): JsonResponse
    {
        $this->ensureSystemBlueprint($blueprint);
        $this->authorize('delete', $blueprint);

        try {
            $blueprint->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete space blueprint', [
                'blueprint_id' => $blueprint->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('validation.blueprint.delete_failed'),
            ], 500);
        }
    }

    private function ensureSystemBlueprint(SpaceBlueprint $blueprint): void
    {
        abort_unless($blueprint->team_id === null, 404);
    }
}
