<?php

namespace App\Actions\Team;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipService;
use Illuminate\Support\Collection;

class RemoveTeamMemberAccess
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(Team $team, User $user): void
    {
        // Fails on the last owner before any space access is touched.
        $this->membershipService->removeTeamMembership($team, $user);

        $spaceIds = $this->getAllSpaceIdsFromTeamAndChildren($team);
        $memberSpaceIds = $user->spaces()->whereIn('spaces.id', $spaceIds)->pluck('spaces.id');

        foreach (Space::query()->findMany($memberSpaceIds) as $space) {
            $this->membershipService->removeSpaceMembership($space, $user);
        }
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
