<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Http\Controllers\Controller;
use App\Http\Requests\Release\AssignContentVersionRequest;
use App\Http\Resources\Management\ReleaseDetailResource;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ReleaseVersionRemoveController extends Controller
{
    public function __invoke(Space $space, Release $release, AssignContentVersionRequest $request): ReleaseDetailResource
    {
        $this->authorize('removeVersions', [$release, $space]);
        abort_if(!!$release->committed_at, 400, 'Release is already committed');

        try {
            ContentVersion::query()
                ->whereIn('id', $request->input('version_ids'))
                ->where('release_id', $release->id)
                ->update(['release_id' => null]);

            $release->load(['versions'])->loadCount(['versions']);

            return new ReleaseDetailResource($release);
        } catch (\Exception $e) {
            Log::error('Failed to remove versions from release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to remove content versions to release');
        }
    }
}
