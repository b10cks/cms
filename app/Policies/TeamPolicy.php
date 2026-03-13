<?php

namespace App\Policies;

use App\Models\Management\Team;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class TeamPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user): bool
    {
        return $user->is_root || $this->authorization()->accessibleTeamIds($user) !== [];
    }

    public function view(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.update');
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.delete');
    }

    public function viewMembers(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.members.view');
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.members.manage');
    }

    public function viewInvites(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.invites.view');
    }

    public function manageInvites(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.invites.manage');
    }

    public function manageSpaceRoles(User $user, Team $team): bool
    {
        return $this->canInTeam($user, $team, 'team.members.manage');
    }
}
