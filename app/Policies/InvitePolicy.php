<?php

namespace App\Policies;

use App\Models\Management\Invite;
use App\Models\User;

class InvitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_root || $user->teams()->exists() || $user->spaces()->exists();
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
            return $user->spaces()
                ->wherePivotIn('role', ['owner', 'admin'])
                ->where('spaces.id', $invite->space_id)
                ->exists();
        }

        if ($invite->team_id) {
            return $user->teams()
                ->wherePivotIn('role', ['admin', 'owner'])
                ->where('teams.id', $invite->team_id)
                ->exists();
        }

        return false;
    }
}
