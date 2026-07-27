<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Actions\Release\PublishRelease;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\ReleaseDetailResource;
use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Services\Audit\AuditActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReleasePublishController extends Controller
{
    public function __invoke(Request $request, Space $space, Release $release, PublishRelease $action): ReleaseDetailResource
    {
        $this->authorize('publish', [$release, $space]);

        try {
            $action->execute($release, $space, auth()->user());
            $release->auditSpaceEvent('published', AuditActor::user($request->user()));

            $release->load(['versions' => fn ($query) => $query->listSummary()])->loadCount(['versions']);

            return new ReleaseDetailResource($release);
        } catch (\Exception $e) {
            Log::error('Failed to publish release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to publish release');
        }
    }
}
