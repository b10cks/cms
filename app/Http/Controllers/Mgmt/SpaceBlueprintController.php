<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Blueprint\CreateSpaceBlueprint;
use App\Actions\Blueprint\UpdateSpaceBlueprint;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesBlueprintSourceSpace;
use App\Http\Filters\Mgmt\SpaceBlueprintFilter;
use App\Http\Requests\SpaceBlueprint\StoreSpaceBlueprintRequest;
use App\Http\Requests\SpaceBlueprint\UpdateSpaceBlueprintRequest;
use App\Http\Resources\Management\SpaceBlueprintListResource;
use App\Http\Resources\Management\SpaceBlueprintResource;
use App\Models\Management\SpaceBlueprint;
use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class SpaceBlueprintController extends Controller
{
    use ResolvesBlueprintSourceSpace;

    public function index(Request $request, Team $team): ResourceCollection
    {
        $this->authorize('viewAny', [SpaceBlueprint::class, $team]);

        $blueprints = SpaceBlueprint::filter(SpaceBlueprintFilter::fromRequest($request))
            ->where('team_id', $team->id)
            ->with(['creator', 'team'])
            ->paginate($this->perPage($request));

        return SpaceBlueprintListResource::collection($blueprints);
    }

    public function store(
        StoreSpaceBlueprintRequest $request,
        Team $team,
        CreateSpaceBlueprint $action
    ): SpaceBlueprintResource {
        $this->authorize('create', [SpaceBlueprint::class, $team]);

        $blueprint = $action->execute(
            $request->validated(),
            $team,
            $request->user(),
            $this->resolveSourceSpace($request),
        );

        return new SpaceBlueprintResource($blueprint->load(['creator', 'team']));
    }

    public function show(Team $team, SpaceBlueprint $blueprint): SpaceBlueprintResource
    {
        $this->ensureBlueprintTeam($blueprint, $team);
        $this->authorize('view', $blueprint);

        return new SpaceBlueprintResource($blueprint->load(['creator', 'team']));
    }

    public function update(
        UpdateSpaceBlueprintRequest $request,
        Team $team,
        SpaceBlueprint $blueprint,
        UpdateSpaceBlueprint $action
    ): SpaceBlueprintResource {
        $this->ensureBlueprintTeam($blueprint, $team);
        $this->authorize('update', $blueprint);

        $blueprint = $action->execute($blueprint, $request->validated());

        return new SpaceBlueprintResource($blueprint->load(['creator', 'team']));
    }

    public function destroy(Team $team, SpaceBlueprint $blueprint): JsonResponse
    {
        $this->ensureBlueprintTeam($blueprint, $team);
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

    private function ensureBlueprintTeam(SpaceBlueprint $blueprint, Team $team): void
    {
        abort_unless($blueprint->team_id === $team->id, 404);
    }
}
