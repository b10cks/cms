<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Actions\Release\CommitRelease;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\ReleaseResource;
use App\Models\Management\Space;
use App\Models\Space\Release;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ReleaseCommitController extends Controller
{
    public function __invoke(Space $space, Release $release, CommitRelease $action): ReleaseResource
    {
        $this->authorize('commit', [$release, $space]);

        try {
            $release->committed_at = now();
            $release->save();
            $release->loadCount(['versions']);
            $action->execute($release, $space);

            return new ReleaseResource($release);
        } catch (\Exception $e) {
            Log::error('Failed to commit release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to commit release');
        }
    }
}
