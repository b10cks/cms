<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Actions\Release\CreateRelease;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ReleaseFilter;
use App\Http\Requests\Release\StoreReleaseRequest;
use App\Http\Requests\Release\UpdateReleaseRequest;
use App\Http\Resources\Management\ReleaseDetailResource;
use App\Http\Resources\Management\ReleaseResource;
use App\Models\Management\Space;
use App\Models\Space\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class ReleaseController extends Controller
{
    /**
     * Display a listing of releases.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [Release::class, $space]);

        $releases = Release::filter(ReleaseFilter::fromRequest($request))
            ->withCount(['versions'])
            ->paginate();

        return ReleaseResource::collection($releases);
    }

    /**
     * Store a newly created release in storage.
     */
    public function store(Space $space, StoreReleaseRequest $request, CreateRelease $action): ReleaseDetailResource
    {
        $this->authorize('create', [Release::class, $space]);

        $release = new Release;
        $action->execute($request->validated(), $release, $request->user());

        $release->loadCount(['versions']);

        return new ReleaseDetailResource($release);
    }

    /**
     * Display the specified release.
     */
    public function show(Space $space, Release $release): ReleaseDetailResource
    {
        $this->authorize('view', [$release, $space]);

        $release->load(['versions' => fn ($query) => $query->listSummary()])->loadCount(['versions']);

        return new ReleaseDetailResource($release);
    }

    /**
     * Update the specified release in storage.
     */
    public function update(Space $space, Release $release, UpdateReleaseRequest $request): ReleaseDetailResource
    {
        $this->authorize('update', [$release, $space]);

        $release->fill($request->validated());

        if (! $release->save()) {
            Log::error('Failed to update release', ['release_id' => $release->id]);
            abort(500, 'Failed to update release');
        }

        $release->loadCount(['versions']);

        return new ReleaseDetailResource($release);
    }

    /**
     * Remove the specified release from storage.
     */
    public function destroy(Space $space, Release $release): JsonResponse
    {
        $this->authorize('delete', [$release, $space]);

        try {
            $release->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the release',
            ], 500);
        }
    }
}
