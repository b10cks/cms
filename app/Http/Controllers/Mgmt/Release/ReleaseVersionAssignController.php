<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Http\Controllers\Controller;
use App\Http\Requests\Release\AssignContentVersionRequest;
use App\Http\Resources\Management\ReleaseDetailResource;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use Illuminate\Support\Facades\Log;

class ReleaseVersionAssignController extends Controller
{
    public function __invoke(Space $space, Release $release, AssignContentVersionRequest $request): ReleaseDetailResource
    {
        $this->authorize('assignVersions', [$release, $space]);
        abort_if(!!$release->committed_at, 400, 'Release is already committed');

        try {
            $versionIds = $request->validated()['version_ids'];

            ContentVersion::query()
                ->whereIn('id', $versionIds)
                ->update(['release_id' => $release->id]);

            $release->load(['versions'])->loadCount(['versions']);

            return new ReleaseDetailResource($release);
        } catch (\Exception $e) {
            Log::error('Failed to assign content versions to release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to assign content versions to release');
        }
    }
}
