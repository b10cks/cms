<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateTeamAvatarRequest;
use App\Http\Resources\Management\TeamResource;
use App\Models\Management\Team;
use App\Services\Image\ImageUploadService;

class TeamAvatarController extends Controller
{
    public function store(UpdateTeamAvatarRequest $request, Team $team, ImageUploadService $uploadService): TeamResource
    {
        $uploadService->uploadForModel(
            $team,
            $request->file('avatar'),
            'avatar',
            'teams/avatars'
        );

        return $this->teamResource($team);
    }

    public function destroy(Team $team, ImageUploadService $uploadService): TeamResource
    {
        $this->authorize('update', $team);

        // Through the service so the disk is decided in exactly one place.
        $uploadService->removeForModel($team, 'avatar');

        return $this->teamResource($team);
    }

    private function teamResource(Team $team): TeamResource
    {
        $team = $team->fresh();
        $team->load(['parent'])->loadCount(['users', 'spaces', 'children']);

        return new TeamResource($team);
    }
}
