<?php

namespace App\Policies;

use App\Models\Management\Invite;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class InvitePolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user): bool
    {
        return $user->is_root
            || $this->authorization()->accessibleTeamIds($user) !== []
            || $this->authorization()->accessibleSpaceIds($user) !== [];
    }

    public function view(User $user, Invite $invite): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $this->canManageInvite($user, $invite);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Invite $invite): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $this->canManageInvite($user, $invite);
    }

    public function resend(User $user, Invite $invite): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $this->canManageInvite($user, $invite);
    }

    private function canManageInvite(User $user, Invite $invite): bool
    {
        if ($invite->space_id) {
            return $this->canInSpace($user, $invite->space, 'space.invites.manage');
        }

        if ($invite->team_id) {
            return $this->canInTeam($user, $invite->team, 'team.invites.manage');
        }

        return false;
    }
}
