<?php

namespace App\Actions\Team;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Support\Collection;

class RemoveTeamMemberAccess
{
    public function execute(Team $team, User $user): void
    {
        $spaceIds = $this->getAllSpaceIdsFromTeamAndChildren($team);

        if ($spaceIds->isNotEmpty()) {
            $user->spaces()->detach($spaceIds);
        }

        $team->users()->detach($user->id);
    }

    private function getAllSpaceIdsFromTeamAndChildren(Team $team): Collection
    {
        $spaceIds = collect();

        $this->collectSpaceIdsFromTeam($team, $spaceIds);

        return $spaceIds;
    }

    private function collectSpaceIdsFromTeam(Team $team, Collection $spaceIds): void
    {
        $team->spaces()->pluck('id')->each(function ($id) use ($spaceIds) {
            $spaceIds->push($id);
        });

        foreach ($team->children as $child) {
            $this->collectSpaceIdsFromTeam($child, $spaceIds);
        }
    }
}
