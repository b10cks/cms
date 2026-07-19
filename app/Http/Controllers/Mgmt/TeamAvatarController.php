<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateTeamAvatarRequest;
use App\Http\Resources\Management\TeamResource;
use App\Models\Management\Team;
use App\Services\Image\ImageUploadService;
use Illuminate\Support\Facades\Storage;

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

    public function destroy(Team $team): TeamResource
    {
        $this->authorize('update', $team);

        if ($team->avatar && Storage::exists($team->avatar)) {
            Storage::delete($team->avatar);
        }

        $team->avatar = null;
        $team->save();

        return $this->teamResource($team);
    }

    private function teamResource(Team $team): TeamResource
    {
        $team = $team->fresh();
        $team->load(['parent'])->loadCount(['users', 'spaces', 'children']);

        return new TeamResource($team);
    }
}
