<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\ReleaseResource;
use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Services\Audit\AuditActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReleaseCancelController extends Controller
{
    public function __invoke(Request $request, Space $space, Release $release): ReleaseResource
    {
        $this->authorize('cancel', [$release, $space]);

        try {
            $release->withoutAudit();
            $release->committed_at = null;
            $release->save();
            $release->auditSpaceEvent('canceled', AuditActor::user($request->user()));

            return new ReleaseResource($release);
        } catch (\Exception $e) {
            Log::error('Failed to cancel release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'An error occurred while cancelling the release');
        }
    }
}
